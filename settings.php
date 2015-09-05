<?php

define('DS', DIRECTORY_SEPARATOR);

function e($var) {
    echo "<pre>";
    print_r($var);
    echo "</pre>";
}

function gc($var)
{
    return get_class_methods($var);
}

class Settings {
    
    const FREEZE_DIR = 'settings';
    
    private $_type;
    private $_method;
    private $_params;
    private $_settings_path;
    private $_home_dir;
    
    public function __construct($type = 'ini') 
    {
        
        if(PHP_SAPI != 'cli') {
            exit('CLI mode only!!');
        }
        
        $this->_type    = $type;
        $this->_method  = !empty($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;
        
        if(!method_exists($this, $this->_method)) {
            $this->help();
        }
        
        $this->_home_dir        = realpath(dirname(__FILE__));
        $this->_settings_path   = $this->_home_dir . DS . self::FREEZE_DIR;
        
        if(!is_dir($this->_settings_path) || !is_writeable($this->_settings_path)) {
            $this->help($this->_settings_path . ' is not writable or does not exist');
        }
        
        $this->_params = array_slice($_SERVER['argv'], 2);
        
        #####dependencies
        //extension_loaded('readline')
        //extension_loaded('zip')
        
        call_user_method_array($this->_method, $this, $this->_params);
        
    }
    
    protected function freeze() 
    {
        /// scan recursively
        
        $cmd = 'cd ' . $this->_home_dir . ';find -name *.' . $this->_type;
        
        exec($cmd, $freezeFiles);
        
        if(!$freezeFiles) {
            $this->help('No configuration files found');
        }
        
        $zipName = sha1(php_uname() . time()) . '_' . gethostname() . '_' . date('Y-m-d');
        
        $zip = new ZipArchive();
        
        $zipName    = $this->_settings_path . DS . $zipName . '.zip';
        
        if($zip->open($zipName , ZIPARCHIVE::CREATE) !== true) {
            throw new Exception('Cannot create zip');
        }
        
        foreach($freezeFiles as $singleFile) {
            $zip->addFile($singleFile);
            $this->display($singleFile . ' - added');
        }
        
        $zip->close();
        
        if(!file_exists($zipName)) {
            $this->help('Error while archiving....');
        }
        
        $this->display('Freeze - Complete');
    }
    
    protected function load()
    {
        
        if(!$zipFiles = $this->getFiles('zip')) {
            $this->help('No `ZIPs` found');
        }
        
        $zipId = !empty($this->_params[0]) ? (int)$this->_params[0] : 0;
        
        if(!$zipId || empty($zipFiles[$zipId - 1])) {
            $this->help('Invalid Zip ID');
        }
        
        $zipFile = $zipFiles[$zipId - 1];
        $zipPath = $this->_settings_path . DS . $zipFile;
        $zipFileName = pathinfo($zipFile, PATHINFO_FILENAME);
        $zipExtractPath = $this->_settings_path . DS . $zipFileName;
        
        $zipArchive = new ZipArchive();
        $zipArchive->open($zipPath);
        $zipArchive->extractTo($zipExtractPath);
        
        if(!is_dir($zipExtractPath) || !is_readable($zipExtractPath)) {
            $this->help('Error while extracting archive');
        }
        
        ///// read extracted TEMP files , and compare them if exists
        
        $cmd = 'cd ' . $zipExtractPath . ';find -name *.' . $this->_type;     
        exec($cmd, $freezeFiles);
        
        $replaceArray = array();
        
        if($freezeFiles) {
            
            foreach($freezeFiles as $sFile) {
                
                
                $sFile = substr($sFile, 1);
                $realFile = $this->_home_dir . $sFile;
                
                $theFileIsNew = false;
                if(!file_exists($realFile)) {
                    //$this->help('Invalid Destination File: ' . $realFile);
                    $theFileIsNew = true;
                }
                
                $freezedFile = $zipExtractPath . $sFile;
                
                if(!file_exists($freezedFile)) {
                    $this->help('Invalid Source File: ' . $freezedFile);
                }
                
                //// NEW
                if($theFileIsNew) {
                    ///
                    
                    $replaceArray[] = array(
                        'realFile'      => $realFile,
                        'freezedFile'   => $freezedFile,
                        'state'         => 'NEW'
                    );
                    
                    $this->display($realFile . ' - NEW');
                    continue;
                    
                }
                
                //// OLD
                $output = array();
                $cmd = 'diff ' . $realFile . ' ' . $freezedFile;
                exec($cmd, $output);
                
                if(!$output) {
                    $this->display($realFile . ' - NO CHANGES');
                    continue;
                }
                $output = array();
                $cmd = 'sdiff  ' . $realFile . ' ' . $freezedFile;
                exec($cmd, $output);
                
                $response = $this->showChanges($realFile, $output);
                
                if($response == 'no') {
                    continue;
                }
                
                /// 1. backup original
                $res = copy($realFile, $realFile . '.backupf');
                
                if(!$res) {
                    $this->display('Cannot create backup file: ' . $realFile . ' --> Skipped', 'red');
                }
                
                $replaceArray[] = array(
                    'realFile'      => $realFile,
                    'freezedFile'   => $freezedFile,
                    'state'         => 'CHANGED'
                );
                
            }
        }
        
        if($replaceArray) {
            foreach($replaceArray as $singleReplace) {
                
                $res = copy($singleReplace['freezedFile'], $singleReplace['realFile']);
                
                if(!$res) {
                    $this->display('Cannot copy freezed file: ' . $realFile . ' --> Nothing changed', 'yellow');
                } else {
                    $this->display('File: ' . $realFile . ' --> ' . ($singleReplace['state']), 'yellow');
                }
                @unlink($singleReplace['realFile'] . '.backupf');
            }
        }
        
        /// cleanup
        $cmd = 'rm -rf ' . $zipExtractPath;
        exec($cmd);
    }
    
    protected function showChanges($file, $lines, $prompt = true)
    {
        $validAnswers = array('yes', 'no');
        
        $this->display($file . ' - CHANGES');
        
        print_r($lines);
        
        foreach($lines as $key => $singleLine) {
            
            continue;
            $singleLine = explode(chr(9), $singleLine);    
            $singleLine = array_filter($singleLine);
            
            if(count($singleLine) < 2) {
                ////no changes on this line: NEW LINE
                continue;
            }
            
            if(count(array_unique($singleLine)) == (count($singleLine) / 2)) {
                ////no changes on this line
                continue;
            }
            
            if(count($singleLine) == 3) {
                //// changed
                $realVal = array_shift($singleLine);
                $changedVal = array_pop($singleLine);
                
                echo "\t\t";
                echo "Line: " . str_pad(($key + 1), 3, ' ', 2) . ": `" . $realVal . '` --> `' . $changedVal . "`";
                echo "\n";
                continue;
            }
            
            if(count($singleLine) == 2) {
                
                $first = array_shift($singleLine);
                $second = array_shift($singleLine);
                
                $changeType = null;
                if($first == '      >') {
                    $changeType = '>';
                } elseif($second == '      <') {
                    $changeType = '<';
                }
                
                if($changeType == '>') { 
                    echo "\t\t";
                    echo "Line: " . str_pad(($key + 1), 3, ' ', 2) . ": `" . '` --> `' . $second . "`";
                    echo "\n";
                    continue;
                } elseif($changeType == '<') {
                    echo "\t\t";
                    echo "Line: " . str_pad(($key + 1), 3, ' ', 2) . ": `" . '` --> `' . $first . "`";
                    echo "\n";
                    continue;
                } else {
                    echo $first;echo "\n\n";
                    echo $second;
                    echo "\n\n";
                    die;
                }
                
            }
            
            $this->help('System Error: Cannot Find Changes');
        }
        
        if($prompt) {
            
            while(!in_array(($response = readline("Replace? (yes/no): ")), $validAnswers)) {
            
            }
        }
        
        return $response;
    }
    
    protected function show()
    {
        if(!$zipFiles = $this->getFiles('zip')) {
            $this->display('No `ZIPs` found');
        }
        
        if($zipFiles) {
            
            foreach($zipFiles as $key => $singleFile) {
                $this->display($key + 1 . ') ' . $singleFile);
            }
        }
    }
    
    protected function getFiles($type = '*', $path = '')
    {
        if(!$path) {
            $path = $this->_settings_path;
        }
        
        $cmd = 'cd ' . $path . ';ls -t *.' . $type;
        
        exec($cmd, $zipFiles);
        
        return $zipFiles;
    }
    
    protected function display($message = '', $color = 'green')
    {
        $colorCode = '0;32m';
        
        if($color == 'yellow') {
            $colorCode = '1;33m';
        } elseif($color == 'red') {
            $colorCode = '1;31m';
        }
        
        if($message) {
            echo chr(27)."[$colorCode$message".chr(27)."[0m";
        }
        
        echo "\n";
        
    }
    protected function help($message = '')
    {

        $message = chr(27)."[1;31m$message".chr(27)."[0m";
        
        echo <<<EOF
        $message
Usage:
    1) freeze   - php settings.php freeze -- freeze all settings(INIs - default)
    2) show     - php settings.php list   -- display all available `ZIPs`
    3) load {n} - php settings load 1   -- load specific ZIP
    4) help     - :)


EOF;
        exit;
        
    }
    
}

$settings = new Settings();
