<?php

namespace RonAppleton\VNameCleaner\Objects;

class VideoObject
{
    public $original_name = null;
    public $file_location = null;

    public $clean_name = null;
    public $year = null;

    public $resolution = null;
    public $encodings = [];
    public $qualities = [];

    public $isSeries = false;
    public $series = null;
    public $episode = null;

    public $tags = [];
}