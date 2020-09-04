<?php
require './vendor/autoload.php';

use JiraRestApi\Issue\IssueService;
use Gitlab\Client as GitlabClient;
use JiraRestApi\JiraException;
use Dotenv\Dotenv;


$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$fromDate = isset($argv[1]) && validateDate($argv[1]) ? new DateTime($argv[1]): new DateTime('-1 month');
$toDate = isset($argv[2]) && validateDate($argv[2]) ? new DateTime($argv[2]): new DateTime();

$gitlab = new GitlabClient();
$gitlab->setUrl($_ENV['GITLAB_URL']);
$gitlab->authenticate($_ENV['GITLAB_TOKEN'], GitlabClient::AUTH_HTTP_TOKEN);

$mergeRequests = $gitlab->mergeRequests()->all($_ENV['GITLAB_PROJECT_ID'], [
    'author_id' => intval($_ENV['GITLAB_AUTHOR_ID']),
    'per_page' => intval($_ENV['GITLAB_PER_PAGE']),
    'created_after' => $fromDate,
    'created_before' => $toDate
]);

$issueService = new IssueService();
$rows = [];
foreach ($mergeRequests as $mr) {
    echo sprintf("%d. [%s][%s] %s \n", $mr['id'], $mr['created_at'], $mr['state'], $mr['title']);
    $issueCode = parseIssueCode($mr['title']);
    if (!$issueCode) continue;

    try {
        $issue = $issueService->get($issueCode);
    } catch (JiraException $e) {
        print("Error Occured! " . $e->getMessage());
    }

    $description = " 
        Доработка проекта согласно заданию: 
        {$_ENV['JIRA_HOST']}/browse/{$issueCode}
        Содержимое работ: 
        {$issue->fields->description}";

    $result = "Приняты merge-request с исходным кодом в репозитории заказчика: \n {$mr['web_url']}";

    if ($mr['state'] === "opened") {
        $result .= "\nЗадание находится на кодревью у заказчика.";
    }

    $rows[$issueCode] = [
        'project' => $issue->fields->project->name,
        'title' => $issue->fields->summary,
        'description' => $description,
        'result' => $result,
        'time' => calculateWorklog($issue->fields->worklog->worklogs, $_ENV['JIRA_USER']),
        'date' => (new DateTime($mr['created_at']))->format('d.m.Y'),
        'status' => $issue->fields->status->name
    ];
}

usort($rows, function($a, $b){
    return strcmp($a['project'], $b['project']);
});


$fp = fopen('report.csv', 'w');
foreach ($rows as $row) {
    fputcsv($fp, $row);
}
fclose($fp);
echo "Csv file report.csv successfully saved.\n";

/**
 * @param $title
 * @return mixed|string
 */
function parseIssueCode($title)
{
    $title = trim($title);
    if (preg_match('#^(\D*-\d*)\s#', $title, $matches)) {
        return strtoupper($matches[1]);
    }

    return '';
}

/**
 * @param $worklogs
 * @param $userName
 * @return mixed|null
 */
function calculateWorklog($worklogs, $userName)
{
    return array_reduce($worklogs, function ( $carry , $worklog ) use ($userName){

        if ( strtolower($worklog->author->name) === strtolower($userName)) {
            $carry += round($worklog->timeSpentSeconds/3600, 1);
        }

        return $carry;
    });
}

/**
 * @param $date
 * @param string $format
 * @return bool
 */
function validateDate($date, $format = 'd.m.Y')
{
    $d = DateTime::createFromFormat($format, $date);

    return $d && $d->format($format) == $date;
}