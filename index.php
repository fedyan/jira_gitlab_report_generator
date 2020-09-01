<?php
require './vendor/autoload.php';

use JiraRestApi\Issue\IssueService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Token authentication
$client = new Gitlab\Client();
$client->setUrl($_ENV['GITLAB_URL']);
$client->authenticate($_ENV['GITLAB_TOKEN'], Gitlab\Client::AUTH_HTTP_TOKEN);


$mergeRequests = $client->mergeRequests()->all($_ENV['GITLAB_PROJECT_ID'], [
    'author_id' => intval($_ENV['GITLAB_AUTHOR_ID']),
    'per_page' => intval($_ENV['GITLAB_PER_PAGE']),
    'created_after' => new DateTime('01.07.2020'),
    'created_before' => new DateTime('01.09.2020')
]);


$rows = [];

foreach ($mergeRequests as $mr) {

    echo sprintf("%d. [%s][%s] %s \n", $mr['id'], $mr['created_at'], $mr['state'], $mr['title']);
    $issueCode = parseIssueCode($mr['title']);
    if (!$issueCode) continue;

    try {
        $issueService = new IssueService();

        $issue = $issueService->get($issueCode);


    } catch (JiraRestApi\JiraException $e) {
        print("Error Occured! " . $e->getMessage());
    }

    $description = " 
        Доработка АС Шиптор согласно заданию: 
        {$_ENV['JIRA_ISSUE_PATH']}{$issueCode}
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
        'time' => calculateWorklog($issue->fields->worklog->worklogs, $_ENV['JIRA_USER'])
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



function calculateWorklog($worklogs, $userName)
{
    return array_reduce($worklogs, function ( $carry , $worklog ) use ($userName){

        if ( strtolower($worklog->author->name) === $userName) {
            $carry += round($worklog->timeSpentSeconds/3600, 1);
        }

        return $carry;
    });
}