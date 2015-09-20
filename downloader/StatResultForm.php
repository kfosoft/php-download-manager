<?php
namespace kfosoft\downloader;

/**
 * Statistic Result Form.
 * @package kfosoft\downloader
 * @version 1.0
 * @copyright (c) 2014-2015 KFOSoftware Team <kfosoftware@gmail.com>
 */
class StatResultForm
{
    /** @var bool file downloaded? */
    public $done;

    /** @var string file uri. */
    public $url;

    /** @var string full path to downloaded file. */
    public $filename;

    /** @var int file size. */
    public $size;

    /** @var int download percent. */
    public $percent;

    /** @var int response status. */
    public $status;

    /** @var int fetched file size. */
    public $fetched;

    /** @var string download speed. */
    public $speed;
}
