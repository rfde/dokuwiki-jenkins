<?php

class JenkinsBuild {
    public $display_name;
    public $build_no;
    public $url;

    public $result;
    public $timestamp;
    public $duration;
    public $description;
    
    public $code_refs;
    public $artifacts;

    function __construct($json_response) {
        $this->display_name = $json_response['fullDisplayName'];
        $this->build_no = $json_response['number'];
        
        $this->url = $json_response['url'];

        $this->result = $json_response['result'];
        $this->timestamp = $json_response['timestamp'];
        $this->duration = $json_response['duration'];
        $this->description = $json_response['description'];
        
        // populate list of code_refs
        $this->code_refs = array();
        foreach ($json_response['actions'] as &$action) {
            switch ($action['_class']) {
                case 'hudson.plugins.git.util.BuildData':
                    $bbbn = $action['buildsByBranchName'];
                    $branch = array_key_first($bbbn);
                    $commit = $action['buildsByBranchName'][$branch]['revision']['branch'][0]['SHA1'];

                    $code_ref = array(
                        'branch' => $branch,
                        'commit' => $commit,
                        'url' => $action['remoteUrls'][0],
                    );
                    $this->code_refs[] = $code_ref;
                    break;
            }
        }
        // populate list of artifacts
        $this->artifacts = array();
        foreach ($json_response['artifacts'] as &$artifact_elem) {
            $this->artifacts[] = array(
                'filename' => $artifact_elem['fileName'],
                'url' => $this->url . '/artifact/' . $artifact_elem['relativePath'],
            );
        }
    }

    function durationPretty() {
        $x = intdiv($this->duration, 1000);
        $milliseconds = $this->duration % 1000;
        $seconds = $x % 60;
        $x = intdiv($x, 60);
        $minutes = $x % 60;
        $x = intdiv($x, 60);
        $hours = $x % 24;
        $x = intdiv($x, 24);
        $days = $x;

        $duration_pretty = '';
        if ($days >= 1) {
            $duration_pretty .= $days.'d ';
        }
        if ($hours >= 1) {
            $duration_pretty .= $hours.'h ';
        }
        if ($minutes >= 1) {
            $duration_pretty .= $minutes.'m ';
        }
        if ($seconds >= 1) {
            $duration_pretty .= $seconds.'s ';
        }
        if ($days === 0 && $hours === 0 && $minutes === 0 && $seconds === 0) {
            $duration_pretty .= $milliseconds.'ms ';
        }

        return trim($duration_pretty);
    }

    function artifactsZipUrl() {
        return $this->url . '/artifact/*zip*/archive.zip';
    }
}

/*
 * Jenkins API
 * @author Algorys
 */
class DokuwikiJenkins {
    private $client;
    private $jenkins_base_url;

    function __construct($jenkins_base_url, $user, $token) {
        $this->jenkins_base_url = $jenkins_base_url;

        // set up curl
        $this->client = curl_init();
        curl_setopt($this->client, CURLOPT_USERPWD, $user . ':' . $token);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, true);
    }
    
    function __destruct() {
        curl_close($this->client);
    }

    /**
     * Sends an HTTP request to the Jenkins JSON api and decodes the response.
     * Returns the decoded JSON in case of success, or null otherwise.
     *
     * @param      $url    The object (url part) to query.
     *
     * @return     Decoded JSON reply or null
     */
    private function requestAndDecodeJSON($object) {
        // set curl options
        curl_setopt($this->client, CURLOPT_URL, $this->jenkins_base_url . '/' . $object . '/api/json');
        curl_setopt($this->client, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ));

        // send http request to jenkins
        $answer = curl_exec($this->client);
        if ($answer === false) {
            return null;
        }

        // decode request (json_decode returns null in case of error)
        $answer_decoded = json_decode($answer, true);

        return $answer_decoded;
    }

    function requestBuild($job, $build) {
        if ($build === 'last') {
            $build = 'lastBuild';
        }
        $url = 'job/' . $job . '/' . $build;
        $json_response = $this->requestAndDecodeJSON($url);
        if (
            ($json_response === null) ||
            (array_key_exists('status', $json_response) && $json_response['status'] !== "200")
        ) {
            return null;
        }
        return new JenkinsBuild($json_response);
    }
}
