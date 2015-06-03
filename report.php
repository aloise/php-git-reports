<?php

date_default_timezone_set('UTC');

// git log --since='last month' --pretty=format:'%an,%ci,%s' > ../august-log.csv

$repos = array( 
	'repo1' => '/path/to/your/repositoty',
	'repo2' => '/another/path/to/your/repositoty'
);

$aggregateByDate = 'F Y';

// exclude specific committers from the log
$excludeCommiters = array('sample@email.com');

$aggregateByDate = 'F Y';


$g = new GitReporter();

echo $g->summaryReport($repos, $aggregateByDate, true, $excludeCommiters );

// echo $g->detailedReport($repos['repo1'], true, $excludeCommiters);


Class GitReporter
{

    function detailedReport($repo, $excludeZero = true, $excludeCommiters = array())
    {

        $r = '';

        if (file_exists($repo)) {
            $log = $this->gitReadCommits($repo);
            foreach ($log as $commit) {
                $data = $this->parseGitMessage($commit['message']);
                $msg = trim($data['message']);

                $cleanMsg = preg_replace("/^\[.\]\s*/", '', $msg);

                $timestamp = strtotime($commit['date']);

                $timeHours = number_format((float)$data['time'] / 3600, 2, ",", "");


                $author = trim($commit['author']);
                $result = $this->parseEmail($author);
                if ($result) {
                    $author = $result;
                }


                if( !in_array( $author, $excludeCommiters ) && !in_array( $commit['author'], $excludeCommiters ) ) {

                    if (!$excludeZero || ($data['time'] > 0)) {

                        $r .= $this->csvRowEncode(array(
                            $timestamp,
                            date('d-m-Y', $timestamp),
                            $author,
                            $cleanMsg,
                            $data['time'],
                            $timeHours
                        ));
                    }
                }
            }

        }

        return $r;
    }


    function summaryReport($repositories, $aggregateByDate, $authorEmailOnly = false, $excludeAuthors = array() )
    {
        if ($repositories) {

            $summary = array();

            foreach ($repositories as $repoName => $repo) {
                if (file_exists($repo)) {
                    $log = $this->gitReadCommits($repo);
                    $resultGroup = $this->processGitLog($log, $aggregateByDate, $authorEmailOnly);


                    if ($resultGroup) {
                        // print("Repository: $repoName\n");


                        foreach ($resultGroup as $dateLabel => $result) {
                            // print("\"$dateLabel\"\n");
                            foreach ($result as $author => $worktime) {

                                if( !in_array( $author , $excludeAuthors ) ) {

                                    $summary[$dateLabel][$author][$repoName] = $worktime;


                                    // $workhours = round($worktime / 3600, 2);
                                }

                                // print("\"$author\",$workhours\n");
                            }
                        }
                    } else {
                        //                printf("Input Git Dir was empty: %s\n", $repo);
                    }
                } else {
                    //            printf("Input Git Dir was not found : %s\n", $repo );
                }
            }

            // summary CSV
            return $this->exportCsv(array_keys($repositories), $summary);

        } else {
            // print("Empty list of repositories");
            return false;
        }
    }

    //print_r($summary);

    function exportCsv($projects, $summary)
    {

        $r = "";

        $row = array('date', 'user');
        foreach ($projects as $project) $row[] = $project;
        $row[] = 'TOTAL';
        $r .= $this->csvRowEncode($row);

        foreach ($summary as $dateLabel => $userData) {
            $r .= $this->csvRowEncode(array($dateLabel));

            foreach ($userData as $user => $projectData) {
                $row = array('', $user);

                $sum = 0;
                foreach ($projects as $project) {
                    if (!empty($projectData[$project])) {
                        $sum += $projectData[$project];
                        $row[] = round($projectData[$project] / 3600, 2);
                    } else {
                        $row[] = 0;
                    }
                }
                $row[] = round($sum / 3600, 2);

                $r .= $this->csvRowEncode($row);
            }
        }

        return $r;

    }

    function csvRowEncode($row)
    {
        $rowEnc = array();
        foreach ($row as $r) $rowEnc[] = '"' . str_replace('"', '""', $r) . '"';
        return join(',', $rowEnc) . "\n";
    }


    function gitReadCommits($dir)
    {
        $output = array();
        chdir($dir);
        exec("git log --all", $output);
        $history = array();
        //dd($output);
        $commit = array();

        foreach ($output as $line) {


            if (strpos($line, 'commit') === 0) {
                if (!empty($commit)) {
                    array_push($history, $commit);
                    $commit = array();
                }
                $commit['hash'] = substr($line, strlen('commit'));
            } else if (strpos($line, 'Author') === 0) {
                $commit['author'] = trim(substr($line, strlen('Author:')), " \t\n\r\0\x0B\"");
            } else if (strpos($line, 'Date') === 0) {
                $commit['date'] = trim(substr($line, strlen('Date:')));
            } else {


                if (array_key_exists('message', $commit)) {
                    $commit['message'] .= trim($line) . ' ';
                } else {
                    $commit['message'] = trim($line);
                }


            }
        }

        return $history;
    }


    function parseGitMessage($message)
    {

        $defaultMultiplier = 'h';
        $multipliers = array(
            'd' => 24 * 3600,
            'h' => 3600,
            'm' => 60,
            's' => 1
        );


        if (preg_match("/^\s*\[[\+\*\-]\]\s*(\d+\.?\d*)\s*([dhms])?\s+\-?\s*(.*)$/si", $message, $matches)) {

            $mult = (empty($matches[2]) || empty($multipliers[$matches[2]])) ? $multipliers[$defaultMultiplier] : $multipliers[$matches[2]];


            $time = (float)$matches[1] * $mult;

            $cleanMessage = trim($matches[count($matches) - 1]);

            //    if( $time <= 0 ) var_dump($message, $matches, $mult);


            return array('time' => $time, 'message' => $cleanMessage);

        } else {
            return array('time' => 0, 'message' => $message);
        }


    }

    function processGitLog($input, $dateGroup, $authorEmailOnly)
    {

        $sumByAuthor = array();

        foreach ($input as $commit) {

            $author = trim($commit['author']);
            if ($authorEmailOnly) {

                $result = $this->parseEmail($author);
                if ($result) {
                    $author = $result;
                }
            }
            $date = date($dateGroup, strtotime($commit['date']));

            $message = $commit['message'];

            $messageData = $this->parseGitMessage($message);

            $time = $messageData['time'];

            if (!isset($sumByAuthor[$date])) {
                $sumByAuthor[$date] = array();
            }
            $sumByAuthor[$date][$author] = isset($sumByAuthor[$date][$author]) ? $time + $sumByAuthor[$date][$author] : $time;

        }


        return $sumByAuthor;


    }

    function parseEmail($str)
    {

        preg_match("/<([^>]+)>/", $str, $matches);
        if ($matches) {
            return strtolower($matches[1]);
        } else {
            return false;
        }

    }

}
