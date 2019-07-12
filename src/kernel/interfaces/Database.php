<?php

namespace Scraper\Kernel\Interfaces;

interface Database {
    public function add(array $config, $conection = 'default');
    public function load();
    public function query($sql = '');
    public function instance();
    public function table($table_name = '');
}