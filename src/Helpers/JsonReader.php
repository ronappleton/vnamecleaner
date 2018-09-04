<?php

namespace RonAppleton\VNameCleaner\Helpers;

use RonAppleton\VNameCleaner\Objects\VideoObject;

class JsonReader
{
    private $data = null;
    private $data_read = false;
    public $video_objects = null;

    private $video_qualities = [
        'hdtv', 'webcam', 'cam', 'hdcam', 'webrip', 'brrip',
        'dvdrip', '4k', '2k', 'dvcam', 'screener', 'web-dl',
    ];

    private $video_encodings = [
        'x264', 'x265', 'divx', 'xvid', 'aac', 'avc',
        'h264', 'h265',
    ];

    public function readJson($json_object, $limit = null)
    {
        $this->data = $json_object;
        $this->makeObjects($limit);
        return $this;
    }

    public function read($filepath, $filename, $limit = null)
    {
        if (empty($filepath)) {
            $fullpath = $filename;
        } else {
            if ($filepath[strlen($filepath)] !== DIRECTORY_SEPARATOR) {
                $fullpath = implode(DIRECTORY_SEPARATOR, [$filepath, $filename]);
            }
            else {
                $fullpath = $filepath . $filename;
            }
        }

        $handle = fopen($fullpath, 'r');

        $json = fread($handle, filesize($fullpath));

        $this->data = json_decode($json);

        fclose($handle);

        $this->makeObjects($limit);
    }

    public function makeObjects($limit = null)
    {
        foreach ($this->data as &$location) {
            if (!empty($location->files)) {
                foreach ($location->files as $file) {
                    if (!empty($file->remote_file_name) && !(empty($file->remote_file_path))) {
                        $vObject = new VideoObject();
                        $vObject->original_name = $file->remote_file_name;
                        $vObject->file_location = $file->remote_file_path;
                        $this->video_objects[] = $vObject;
                        if (!empty($limit)) {
                            if (count($this->video_objects) == $limit) {
                                $this->clearData();
                                return $this;
                            }
                        }
                    }
                }
            }
        }
        $this->clearData();
        return $this;
        // Bare in mind if these are iterated over it should become a generator..
    }

    private function clearData() {
        $this->data_read = true;
        unset($this->data);
    }

    public function processVideos()
    {
        foreach ($this->video_objects as &$video) {
            $video->clean_name = urldecode($this->removeExtension($video->original_name));
            $this->findSeries($video);
            $this->findResolution($video);
            $this->findVideoQualities($video);
            $this->findVideoEncodings($video);
            $this->findVideoYear($video);
            $this->washVideoName($video);
            $this->makeTags($video);
        }

        return $this;
    }

    public function getVideos()
    {
        foreach ($this->video_objects as $video_object) {
            yield $video_object;
        }
    }

    private function removeExtension($original_name) {
        $video_name_parts = explode('.', $original_name);
        if ($video_name_parts > 1) {
            unset($video_name_parts[count($video_name_parts) - 1]);
        }

        return implode('_', $video_name_parts);
    }

    private function findVideoYear(&$video)
    {
        $re = '/(\d{4})/m';
        preg_match_all($re, $video->clean_name, $matches, PREG_SET_ORDER, 0);
        if (!empty($matches)) {
            $years = [];
            foreach ($matches as $match) {
                // If the year is higher than the year of the first film (1888 - The Roundhay Garden Scene)
                // we will keep it
                if ((int) $match[1] > 1888 && (int) $match[1] <= (int) date('Y')) {
                    $years[] = (int) $match[1];
                }
            }
            if (!empty($years)) {
                if (count($years) === 1) {
                    $video->year = $years[0];
                } else {
                    if (!empty($years)) {
                        if (count($years) === 1) {
                            $year = reset($years);
                            $video->year = $year;
                        } else {
                            $max = max($years);
                            if ($max <= (int) date('Y')) {
                                // Make sure we are not giving the year the resolution
                                if (!contains($video->resolution, $max)) {
                                    $video->year = $max;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function findSeries(&$video)
    {
        $re = '/([sS][0-9]{1,4})([eE][0-9]{1,4})/m';
        preg_match($re, $video->clean_name, $matches);
        if (!empty($matches) && count($matches) === 3) {
            $video->isSeries = true;
            $video->series = (int) str_replace('s', '', strtolower($matches[1]));
            $video->episode = (int) str_replace('e', '', strtolower($matches[2]));
        }
    }

    private function findResolution(&$video)
    {
        $re = '/([0-9]{3,4}[p|i])/m';
        preg_match($re, $video->clean_name, $matches);
        if (!empty($matches)) {
            $video->resolution = $matches[0];
        }
    }

    private function findVideoQualities(&$video)
    {
        foreach ($this->video_qualities as $quality)
        {
            if (contains(strtolower($video->clean_name), $quality)) {
                $video->qualities[] = $quality;
            }
        }
    }

    private function findVideoEncodings(&$video)
    {
        foreach ($this->video_encodings as $encoding)
        {
            if (contains(strtolower($video->clean_name), $encoding)) {
                $video->encodings[] = $encoding;
            }
        }
    }

    private function washVideoName(&$video)
    {
        $video_name_parts = explode('_', strtolower($video->clean_name));
        print_r($video_name_parts);
        foreach ($video_name_parts as $key => $name_part) {
            if (empty($name_part)) {
                unset($video_name_parts[$key]);
            } else {
                if (contains($this->video_qualities, $name_part)) {
                    unset($video_name_parts[$key]);
                    continue;
                }

                if (contains($this->video_encodings, $name_part)) {
                    unset($video_name_parts[$key]);
                    continue;
                }

                if (contains($video->resolution, $name_part)) {
                    unset($video_name_parts[$key]);
                    continue;
                }

                if (!empty($series1 = $this->buildSeriesString($video))) {
                    if (contains($series1, $name_part)) {
                        unset($video_name_parts[$key]);
                        continue;
                    }
                }
                if (!empty($series2 = $this->buildSeriesString($video, true))) {
                    if (contains($series2, $name_part)) {
                        unset($video_name_parts[$key]);
                        continue;
                    }
                }
                if (contains((string) $video->year, $name_part)) {
                    unset($video_name_parts[$key]);
                }
            }
        }
        print_r($video_name_parts);
        $video->clean_name = implode(' ', $video_name_parts);
    }

    private function makeTags(&$video)
    {
        $name_parts = explode(' ', $video->clean_name);

        foreach ($name_parts as $name_part) {
            $video->tags[] = $name_part;
        }

        if (!empty($video->year)) {
            $video->tags[] = $video->year;
        }

        if (!empty($video->resolution)) {
            $video->tags[] = $video->resolution;
        }

        if (!empty($video->encodings)) {
            foreach ($video->encodings as $encoding) {
                $video->tags[] = $encoding;
            }
        }

        if (!empty($video->qualities)) {
            foreach ($video->qualities as $quality) {
                $video->tags[] = $quality;
            }
        }

        if (!empty($video->isSeries)) {
            $video->tags[] = $this->buildSeriesString($video, false);
            $video->tags[] = $this->buildSeriesString($video, true);
            $video->tags[] = $this->buildSeriesString($video, false, true, false);
            $video->tags[] = $this->buildSeriesString($video, true, true, false);
            $video->tags[] = $this->buildSeriesString($video, false, false, true);
            $video->tags[] = $this->buildSeriesString($video, true,  false, true);
        }
    }

    private function buildSeriesString(&$video, $leading_zeros = false, $get_series = true, $get_episode = true)
    {
        if (empty($video->isSeries)) {
            return false;
        }

        if ($leading_zeros) {
            if ($get_series) {
                $series = $this->formatForSeries(true, $video->series);
            }
            if ($get_episode) {
                $episode = $this->formatForSeries(false,$video->episode);
            }

            $output = '';
            $output .= $get_series ? $series : '';
            $output .= $get_episode ? $episode : '';
            return $output;
        } else {
            $output = '';
            $output .= $get_series ? "s{$video->series}" : '';
            $output .= $get_episode ? "e{$video->episode}" : '';
            return $output;
        }


    }

    private function formatForSeries($series = true, $number) {
        $prefix = $series ? 's' : 'e';
        if ($number > 9) {
            return "{$prefix}{$number}";
        } else {
            return "{$prefix}0{$number}";
        }
    }

    public function printVideos() {
        foreach ($this->video_objects as $video) {
            print_r($video);
        }
    }

    public function printVideosWithYear()
    {
        foreach ($this->video_objects as $video) {
            if (!empty($video->year)) {
                print_r($video);
            }
        }
    }
}