<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

use App\Jobs\ProcessSubmission;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once 'include/pdo.php';
require_once 'include/do_submit.php';
require_once 'include/version.php';

use CDash\Config;
use CDash\Model\Build;
use CDash\Model\PendingSubmissions;
use CDash\Model\Project;
use CDash\Model\Site;
use CDash\ServiceContainer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\InputStream;

$config = Config::getInstance();
$service = ServiceContainer::getInstance();

// Helper function to display the message
if (!function_exists('displayReturnStatus')) {
    function displayReturnStatus($statusarray, $response_code)
    {
        // NOTE: we can't use Laravel's response() helper function
        // until CTest learns how to properly parse the XML out of it.
        http_response_code($response_code);

        $version = config('cdash.version');
        echo "<cdash version=\"{$version}\">\n";
        foreach ($statusarray as $key => $value) {
            echo "  <{$key}>{$value}</{$key}>\n";
        }
        echo "</cdash>\n";

        return $response_code;
    }
}

$statusarray = [];

// Check if we can connect to the database.
try {
    $pdo = \DB::connection()->getPdo();
} catch (\Exception $e) {
    $statusarray['status'] = 'ERROR';
    $statusarray['message'] = 'Cannot connect to the database.';
    return displayReturnStatus($statusarray, Response::HTTP_SERVICE_UNAVAILABLE);
}

@set_time_limit(0);

// If we have a POST we forward to the new submission process
if (isset($_POST['project'])) {
    return post_submit();
}
if (isset($_GET['buildid'])) {
    return put_submit_file();
}

$stmt = $pdo->prepare(
    'SELECT id, authenticatesubmissions FROM project WHERE name = ?');

$projectid = null;
$projectname = $_GET['project'];
if (pdo_execute($stmt, [$projectname])) {
    $row = $stmt->fetch();
    if ($row) {
        $projectid = $row['id'];
        $authenticate_submissions = $row['authenticatesubmissions'];
    }
}

// If not a valid project we return
if (!$projectid) {
    \Log::info("Not a valid project. projectname: $projectname in submit.php");
    $statusarray['status'] = 'ERROR';
    $statusarray['message'] = 'Not a valid project.';
    return displayReturnStatus($statusarray, Response::HTTP_NOT_FOUND);
}

// Catch the fatal errors during submission
register_shutdown_function('PHPErrorHandler', $projectid);

// Remove some old builds if the project has too many.
$project = new Project();
$project->Name = $projectname;
$project->Id = $projectid;
$project->CheckForTooManyBuilds();

// Check for valid authentication token if this project requires one.
if ($authenticate_submissions && !valid_token_for_submission($projectid)) {
    $statusarray['status'] = 'ERROR';
    $statusarray['message'] = 'Invalid Token';
    return displayReturnStatus($statusarray, Response::HTTP_FORBIDDEN);
}

$expected_md5 = isset($_GET['MD5']) ? htmlspecialchars(pdo_real_escape_string($_GET['MD5'])) : '';

// Check if CTest provided us enough info to assign a buildid.
$pendingSubmissions = $service->create(PendingSubmissions::class);
$buildid = null;
if (isset($_GET['build']) && isset($_GET['site']) && isset($_GET['stamp'])) {
    $build = $service->create(Build::class);
    $build->Name = pdo_real_escape_string($_GET['build']);
    $build->ProjectId = $projectid;
    $build->SetStamp(pdo_real_escape_string($_GET['stamp']));
    $build->StartTime = gmdate(FMT_DATETIME);
    $build->SubmitTime = $build->StartTime;

    if (isset($_GET['subproject'])) {
        $build->SetSubProject(pdo_real_escape_string($_GET['subproject']));
    }

    $site = $service->create(Site::class);
    $site->Name = pdo_real_escape_string($_GET['site']);
    $site->Insert();
    $build->SiteId = $site->Id;
    $pendingSubmissions->Build = $build;

    if ($build->AddBuild()) {
        // Insert row to keep track of how many submissions are waiting to be
        // processed for this build. This value will be incremented
        // (and decremented) later on.
        $pendingSubmissions->NumFiles = 0;
        $pendingSubmissions->Save();
    }
    $buildid = $build->Id;
}

// Save the incoming file in the inbox directory.
$filename = "{$projectname}_" . \Illuminate\Support\Str::uuid()->toString() . '.xml';
$fp = request()->getContent(true);
if (!Storage::put("inbox/{$filename}", $fp)) {
    \Log::error("Failed to save submission to inbox for $projectname (md5=$expected_md5)");
    $statusarray['status'] = 'ERROR';
    $statusarray['message'] = 'Failed to save submission file.';
    return displayReturnStatus($statusarray, Response::HTTP_INTERNAL_SERVER_ERROR);
}

if ($expected_md5) {
    // Check that the md5sum of the file matches what we were told to expect.
    $md5sum = md5_file(Storage::path("inbox/{$filename}"));
    if ($md5sum != $expected_md5) {
        Storage::delete("inbox/{$filename}");
        $statusarray['status'] = 'ERROR';
        $statusarray['message'] = "md5 mismatch. expected: {$expected_md5}, received: {$md5sum}";
        return displayReturnStatus($statusarray, Response::HTTP_BAD_REQUEST);
    }
}

if (!is_null($buildid)) {
    $pendingSubmissions->Increment();
}
ProcessSubmission::dispatch($filename, $projectid, $buildid, $expected_md5);
fclose($fp);
unset($fp);

$statusarray['status'] = 'OK';
$statusarray['message'] = '';
if (!is_null($buildid)) {
    $statusarray['buildId'] = $buildid;
}
return displayReturnStatus($statusarray, Response::HTTP_OK);
