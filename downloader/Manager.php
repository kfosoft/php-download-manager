<?php
namespace kfosoft\downloader;

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
        'finished' => '/^FINISHED --\d{1,4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}--/',
        'status'   => '/.{0,} \d{3} .{0,}/',
        'url'      => "/(\w{3,4}:\/\/)+[\w\.\/\d]{1,}/",
        'fetched'  => '/(\[\d{1,}\/\d{1,}\])/',
        'percent'  => '/\d{1,3}%/',
        'speed'    => '/\(([\d]?\.?(\d{1,} [\D \/]{1,}))\)/',
        'size'     => '/^\S+: ([0-9,\s]+)\(/',
        'filename' => '/‘(\/{1}\w{1,}\.?\w{1,}?){1,}’/'
    ];

    public function __construct(
        $downloadDir = '/home/ubuntu/public_html/downloads',
        $tmpDir = '/home/ubuntu/temp',
        $logFile = '/home/ubuntu/temp/download.log'
    ) {
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
    public function addJob($url, $directoryPrefix = null)
    {
        $sid = $this->sid($url);

        $urlFile = "{$this->tmpDir}/{$sid}.url";
        $statFile = "{$this->tmpDir}/{$sid}.stat";
        $pidFile = "{$this->tmpDir}/{$sid}.pid";

        $this->filePutContents($urlFile, $url);
        $this->filePutContents($this->logFile, $url, true);

        $safeUrlFile = escapeshellarg($urlFile);
        $safeUrl = escapeshellarg($url);
        $safeDownloadDir = escapeshellarg(!is_null($directoryPrefix) ? $this->downloadDir . $directoryPrefix : $this->downloadDir);
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
     * @return StatResultForm
     */
    public function getDetails($sid)
    {
        $statFile = "{$this->tmpDir}/{$sid}.stat";
        if (!@is_file($statFile)) {
            return false;
        }

        $fileResource = fopen($statFile, 'rb');

        $result = new StatResultForm();

        while (!feof($fileResource)) {
            $line = fgets($fileResource, 2048); // read a line
            if (preg_match($this->_patterns['fetched'], $line, $matches)) {
                $result->fetched = substr(explode('/', $matches[0])[0], 1);
            }

            switch (true) {
                case preg_match($this->_patterns['url'], $line, $matches) :
                    $result->url = $matches[0];
                    continue;
                case preg_match($this->_patterns['size'], $line, $matches) :
                    $result->size = str_replace([' ', ','], '', $matches[1]);
                    continue;
                case preg_match($this->_patterns['filename'], $line, $matches) : // Destination file
                    foreach ($matches as $match) {
                        if (is_file($match = str_replace(['’', '‘'], ['', ''], $match))) {
                            $result->filename = $match;
                            break;
                        }
                    }
                    continue;
                case preg_match($this->_patterns['percent'], $line, $matches) :
                    $result->percent = (int)($matches[0]);
                    continue;
                case preg_match($this->_patterns['speed'], $line, $matches) :
                    $result->speed = ($matches[1]);
                    continue;
                case preg_match($this->_patterns['status'], $line, $matches) && preg_match('/\d{3}/', $matches[0],
                        $status):
                    $result->status = !isset($status[0]) ? null : $status[0];
                    continue;
                case preg_match($this->_patterns['finished'], $line, $matches):
                    $result->done = true;
                    break;
            }
        }

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

    /**
     * @param string $url for create sid.
     * @return string sid.
     */
    public function sid($url)
    {
        return md5(trim($url));
    }
}
