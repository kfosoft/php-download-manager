<?php
namespace kfosoft\downloader;

use Exception;

/**
 * Download Manager class. It has method for download files with wget.
 * @package kfosoft\downloader
 * @version 1.0
 * @copyright (c) 2014-2015 KFOSoftware Team <kfosoftware@gmail.com>
 */
class Manager
{
    /** @var string This is where the downloads and status files go. Make sure this directory exists and is WRITABLE by the webserver process! */
    public $downloadDir = '';

    /** @var string This is where the downloads and status files go. Make sure this directory exists and is WRITABLE by the webserver process! */
    public $tmpDir = '';

    /** @var string This is where the downloads and status files go. Make sure this directory exists and is WRITABLE by the webserver process! */
    public $logFile = '';

    /** @var string Path and name of your server's Wget-compatible binary */
    public $wget = '/usr/local/bin/wget';

    /** @var string Extra options to Wget go here. man wget for details. */
    public $wgetOptions = '--continue --user-agent="PHP-Download-Manager/1.0 (GS/OS 6.0.5; AppleIIgs)" --tries="10" --random-wait --waitretry="10"'; //--limit-rate="25k"

    /** @var array Stats cache. */
    public $statsCache = [];

    /** @var array Preg replace patterns. */
    private $_patterns = [
        0 => "/^\\S+: ([0-9,\\s]+)\\(/",
        1 => "/^\\s*=> [`\"](.*?)['\"]$/i",
        2 => "/^[^:]+: [`\"](.*?)['\"]$/i",
        3 => "/^\\s*([0-9]+[kmgte]) [,. ]{54}\\s*([0-9]{1,3}%)?\\s+([0-9.,]+\\s*[kmgte](?:\\/s)?)\\s*([0-9dhms]*)/i",
        4 => "/^.*?\\(([^)]+)[^']+ saved \\[([^\\]]+)]$/i",
        5 => "/ --[0-9:]+--/i",
    ];

    public function __construct($downloadDir = '/home/ubuntu/public_html/downloads', $tmpDir = '/home/ubuntu/temp', $logFile = '/home/ubuntu/temp/download.log')
    {
        $this->downloadDir = $downloadDir;
        $this->tmpDir = $tmpDir;
        $this->logFile = $logFile;
        (!file_exists($this->tmpDir)) && @mkdir($this->tmpDir, 0700); // attempt to create; it may fail...
        (!file_exists($this->downloadDir)) && @mkdir($this->downloadDir, 0700); // attempt to create; it may fail...
    }

    /**
     * Add job.
     * @param string $url url job.
     */
    public function addJob($url)
    {
        $url = trim($url);
        $sid = md5($url);

        $urlFile = "{$this->tmpDir}/{$sid}.url";
        $statFile = "{$this->tmpDir}/{$sid}.stat";
        $pidFile = "{$this->tmpDir}/{$sid}.pid";

        $this->filePutContents($urlFile, $url);
        $this->filePutContents($this->logFile, $url, true);

        $safeUrlFile = escapeshellarg($urlFile);
        $safeUrl = escapeshellarg($url);
        $safeDownloadDir = escapeshellarg($this->downloadDir);
        $safeStatFile = escapeshellarg($statFile);
        $advancedOptions = "--referer={$safeUrl} --background --input-file={$safeUrlFile} --progress=dot --directory-prefix={$safeDownloadDir} --output-file={$safeStatFile}";

        exec("{$this->wget} {$this->wgetOptions} {$advancedOptions}", $output);
        preg_match('/[0-9]+/', $output[0], $output);

        $this->filePutContents($pidFile, $output[0]);
    }

    /**
     * Pause job.
     * @param string $sid sid.
     * @return bool
     */
    public function pauseJob($sid)
    {
        if (!@is_file("{$this->tmpDir}/{$sid}.stat")) {
            return false;
        }

        return $this->getStats('is_running', $sid) && posix_kill($this->getStats('pid', $sid), 15);
    }

    /**
     * Resume job.
     * @param string $sid sid.
     * @return bool
     */
    public function resumeJob($sid)
    {
        if ($this->getStats('is_running', $sid)) {
            return false;
        }

        $details = $this->getDetails($sid);

        if ($details['done'] && file_exists($details['saveFile'])) {
            return false;
        }

        $this->addJob($details['url']);

        return true;
    }

    /**
     * Remove job.
     * @param string $sid sid.
     * @return bool
     */
    public function removeJob($sid)
    {
        @unlink("{$this->tmpDir}/{$sid}.url");
        @unlink("{$this->tmpDir}/{$sid}.stat");
        @unlink("{$this->tmpDir}/{$sid}.pid");
        return true;
    }

    /**
     * Get job list.
     * @return array job list.
     */
    public function getJobList()
    {
        $result = [];

        foreach (glob("{$this->tmpDir}/*.stat") as $filename) {
            if ($filename) {
                $result[] = basename($filename, ".stat");
            }
        }

        return $result;
    }

    /**
     * Get disc statistic.
     * @return array disc statistic.
     */
    public function getDiskUsage()
    {
        $result = [];
        $result['total'] = disk_total_space($this->downloadDir);
        $result['free'] = disk_free_space($this->downloadDir);
        $result['used'] = $result['total'] - $result['free'];
        $result['percent'] = $this->safeNumberFormat(100 * $result['used'] / $result['total'], 2);

        return $result;
    }

    /**
     * Get statistics.
     * @param string $what type stats.
     * @param string $sid sid.
     * @param bool $cache use cache.
     * @return int|null
     */
    public function getStats($what, $sid = '', $cache = true)
    {
        $result = null;
        if ($cache) {
            if (!empty($this->statsCache[$what . $sid])) {
                return $this->statsCache[$what . $sid];
            }
        }

        switch ($what) {
            case 'pid':
                $pidFile = "{$this->tmpDir}/{$sid}.pid";
                $result = file_exists($pidFile) ? (int)file_get_contents($pidFile) : -1;
                break;
            case 'is_running':
                $pid = $this->getStats('pid', $sid, $cache);
                $result = intval(`ps axopid,command |grep -v grep |grep {$pid} |grep -c wget`);
                break;
        }
        return $this->statsCache[$what . $sid] = $result;
    }

    /**
     * Get stat file.
     * @param string $sid sid.
     * @return string
     */
    public function getStatFile($sid)
    {
        return ("{$this->tmpDir}/{$sid}.stat");
    }

    /**
     * Get details.
     * @param string $sid sid.
     * @param bool $verbose
     * @return array
     */
    public function getDetails($sid, $verbose = false)
    {
        $statFile = "{$this->tmpDir}/{$sid}.stat";
        if (!@is_file($statFile)) {
            return false;
        }

        $fileResource = fopen($statFile, 'rb');

        $result = [
            'done'     => 0,
            'url'      => '',
            'saveFile' => '',
            'size'     => '- Unknown -',
            'percent'  => '- Unknown -',
            'fetched'  => 0,
            'speed'    => 0,
            'eta'      => 'n/a'
        ];

        $count = 0;
        while (!feof($fileResource)) {
            $count++;
            $line = fgets($fileResource, 2048); // read a line

            switch (true) {
                case $count == 1 : // URL
                    $tmp = explode(" ", $line, 2);
                    $result['url'] = trim($tmp[1]);
                    break;
                case preg_match($this->_patterns[0], $line, $matches) : // Length
                    $result['size'] = str_replace([' ', ','], '', $matches[1]);
                    break;
                case preg_match($this->_patterns[1], $line, $matches) : // Destination file
                    $result['saveFile'] = $matches[1];
                    break;
                case preg_match($this->_patterns[2], $line, $matches) : // Destination file on newer wget
                    $result['saveFile'] = $matches[1];
                    break;
                case preg_match($this->_patterns[3], $line, $matches) :
                    $result['fetched'] = $matches[1];
                    $result['percent'] = floatval($matches[2]);
                    $result['speed'] = $matches[3];
                    $result['eta'] = $matches[4];
                    break;
                case preg_match($this->_patterns[4], $line, $matches) :
                    $result['fetched'] = $matches[2];
                    $result['percent'] = 100;
                    $result['speed'] = $matches[1];
                    break;
                case preg_match($this->_patterns[5], $line, $matches) :
                    $result['done'] = 1;
                    break;
            }
        }
        fclose($fileResource);

        //$result['exists'] = $result['saveFile'] && file_exists($result['saveFile']);

        return $result;
    }

    /**
     * File put content.
     * @param string $filename filename.
     * @param string $data file data.
     * @param bool $append append or new file.
     */
    public function filePutContents($filename, $data, $append = false)
    {
        $fileResource = fopen($filename, $append ? 'ab' : 'wb');
        chmod($filename, 0600); // This file may contain passwords
        fwrite($fileResource, $data . "\n");
        fclose($fileResource);
    }

    /**
     * @param string $sid sid.
     * @return bool
     */
    public function removeFile($sid)
    {
        if ($this->getStats('is_running', $sid)) {
            return false;
        }

        $details = $this->getDetails($sid);

        if (file_exists($details['saveFile'])) {
            @unlink($details['saveFile']);
        }

        return true;
    }

    /**
     * Safe number format.
     * @param string|int $value value.
     * @param int $precision
     * @return string
     */
    public function safeNumberFormat($value, $precision = 0)
    {
        if (is_numeric($value)) {
            return (number_format($value, $precision));
        } else {
            return ($value);
        }
    }
}
