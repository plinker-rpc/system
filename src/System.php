<?php
/*
 +------------------------------------------------------------------------+
 | Plinker-RPC PHP                                                        |
 +------------------------------------------------------------------------+
 | Copyright (c)2017-2018 (https://github.com/plinker-rpc/core)           |
 +------------------------------------------------------------------------+
 | This source file is subject to MIT License                             |
 | that is bundled with this package in the file LICENSE.                 |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@cherone.co.uk so we can send you a copy immediately.        |
 +------------------------------------------------------------------------+
 | Authors: Lawrence Cherone <lawrence@cherone.co.uk>                     |
 +------------------------------------------------------------------------+
 */
 
namespace Plinker\System;

/**
 * System information
 *
 * Some methods require root and not all work with windows.
 */
class System
{
    /**
     *
     */
    public function __construct()
    {
        $this->tmp_path = './.plinker';

        if (!file_exists($this->tmp_path)) {
            mkdir($this->tmp_path, 0755, true);
        }

        $this->host_os = trim(strtoupper(strstr(php_uname(), ' ', true)));
    }

    /**
     * Enumerate multiple methods, saves on HTTP calls
     *
     * @param array $methods
     */
    public function enumerate($methods = [], $params = [])
    {
        if (is_array($methods)) {
            $return = [];
            foreach ($methods as $key => $value) {
                if (is_array($value)) {
                    $return[$key] = $this->$key(...$value);
                } else {
                    $return[$value] = $this->$value();
                }
            }
            return $return;
        } elseif (is_string($methods)) {
            return $this->$methods(...$params);
        }
    }

    /**
     * Check system for updates
     *
     * @return int 1=has updates, 0=no updates, -1=unknown
     */
    public function system_updates()
    {
        if (file_exists($this->tmp_path.'/check-updates')) {
            unlink($this->tmp_path.'/check-updates');
        }

        if ($this->host_os === 'WINDOWS') {
            $updSess = new \COM("Microsoft.Update.Session");
            $updSrc = $updSess->CreateUpdateSearcher();
            $result = $updSrc->Search('IsInstalled=0 and Type=\'Software\' and IsHidden=0');
            return !empty($result->Updates->Count) ? '1' : '0';
        }

        if ($this->distro() === 'UBUNTU') {
            $get_updates = shell_exec('apt-get -s dist-upgrade');
            if (preg_match('/^(\d+).+upgrade.+(\d+).+newly\sinstall/m', $get_updates, $matches)) {
                $result = (int) $matches[1] + (int) $matches[2];
            } else {
                $result = 0;
            }
            return !empty($result) ? '1' : '0';
        }

        if ($this->distro() === 'CENTOS') {
            exec('yum check-update', $output, $exitCode);
            return ($exitCode == 100) ? '1' : '0';
        }
        return '-1';
    }

    /**
     * Get diskspace
     *
     * @param  string $path
     * @return int
     */
    public function disk_space($path = '/')
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $disks =  $wmi->ExecQuery("Select * from Win32_LogicalDisk");

            foreach ($disks as $d) {
                if ($d->Name == $path) {
                    $ds = $d->Size;
                    $df = $d->FreeSpace;
                }
            }
        } else {
            $ds = disk_total_space($path);
            $df = disk_free_space($path);
        }
        return ($df > 0 && $ds > 0 && $df < $ds) ? floor($df/$ds * 100) : 0;
    }

    /**
     * Get total diskspace
     *
     * @param  string $path
     * @return int
     */
    public function total_disk_space($path = '/')
    {
        $ds = 0;
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $disks =  $wmi->ExecQuery("Select * from Win32_LogicalDisk");

            foreach ($disks as $d) {
                if ($d->Name == $path) {
                    $ds = $d->Size;
                }
            }
        } else {
            $ds = disk_total_space($path);
        }
        return $ds;
    }

    /**
     * Get memory usage
     *
     * @return array
     */
    public function memory_stats()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $os =  $wmi->ExecQuery("SELECT * FROM Win32_OperatingSystem");

            foreach ($os as $m) {
                $mem_total = $m->TotalVisibleMemorySize;
                $mem_free = $m->FreePhysicalMemory;
            }

            $prefMemory = $wmi->ExecQuery("SELECT * FROM Win32_PerfFormattedData_PerfOS_Memory");

            foreach ($prefMemory as $pm) {
                $mem_cache = $pm->CacheBytes/1024;
            }
            $mem_buff = 0;
        } else {
            $fh = fopen('/proc/meminfo', 'r');

            $mem_free = $mem_buff = $mem_cache = $mem_total = 0;

            while ($line = fgets($fh)) {
                $pieces = array();
                if (preg_match('/^MemTotal:\s+(\d+)\skB$/', $line, $pieces)) {
                    $mem_total = $pieces[1];
                }
                if (preg_match('/^MemFree:\s+(\d+)\skB$/', $line, $pieces)) {
                    $mem_free = $pieces[1];
                }
                if (preg_match('/^Buffers:\s+(\d+)\skB$/', $line, $pieces)) {
                    $mem_buff = $pieces[1];
                }
                if (preg_match('/^Cached:\s+(\d+)\skB$/', $line, $pieces)) {
                    $mem_cache = $pieces[1];
                    break;
                }
            }
            fclose($fh);
        }

        $result['used']  = round(($mem_total - ($mem_buff + $mem_cache + $mem_free)) * 100 / $mem_total);
        $result['cache'] = round(($mem_cache + $mem_buff) * 100 / $mem_total);
        $result['free']  = round($mem_free * 100 / $mem_total);
        return $result;
    }

    /**
     * Get memory total kB
     *
     * @return int
     */
    public function memory_total()
    {
        $mem_total = 0;
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $os =  $wmi->ExecQuery("SELECT * FROM Win32_OperatingSystem");

            foreach ($os as $m) {
                $mem_total = $m->TotalVisibleMemorySize;
            }
        } else {
            $fh = fopen('/proc/meminfo', 'r');

            while ($line = fgets($fh)) {
                $pieces = array();
                if (preg_match('/^MemTotal:\s+(\d+)\skB$/', trim($line), $pieces)) {
                    $mem_total = $pieces[1];
                    break;
                }
            }
            fclose($fh);
        }
        return $mem_total;
    }

    /**
     * Get CPU usage in percentage
     *
     * @return int
     */
    public function cpu_usage()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $cpus = $wmi->ExecQuery("SELECT LoadPercentage FROM Win32_Processor");

            foreach ($cpus as $cpu) {
                $return = $cpu->LoadPercentage;
            }
        } else {
            $return = shell_exec('top -d 0.5 -b -n2 | grep "Cpu(s)"|tail -n 1 | awk \'{print $2 + $4}\'');
        }
        return trim($return);
    }

    /**
     * Get system machine-id
     *  - Generates one if does not have one (windows).
     *
     * @return string
     */
    public function machine_id()
    {
        // check stmp path
        if (!file_exists($this->tmp_path.'/system')) {
            mkdir($this->tmp_path.'/system', 0755, true);
        }

        // file already generated
        if (file_exists($this->tmp_path.'/system/machine-id')) {
            return file_get_contents($this->tmp_path.'/system/machine-id');
        }

        if (file_exists('/var/lib/dbus/machine-id')) {
            $id = trim(`cat /var/lib/dbus/machine-id`);
            file_put_contents($this->tmp_path.'/system/machine-id', $id);
            return $id;
        }

        if (file_exists('/etc/machine-id')) {
            $id = trim(`cat /etc/machine-id`);
            file_put_contents($this->tmp_path.'/system/machine-id', $id);
            return $id;
        }

        $id = sha1(uniqid(true));
        file_put_contents($this->tmp_path.'/system/machine-id', $id);
        return $id;
    }

    /**
     * Get netstat output
     *
     * @return string
     */
    public function netstat($parse = true)
    {
        $result = trim(shell_exec('netstat -pant'));

        if ($parse) {
            $lines = explode(PHP_EOL, $result);
            unset($lines[0]);
            unset($lines[1]);

            $columns = [
                'Proto',
                'Recv-Q',
                'Send-Q',
                'Local Address',
                'Foreign Address',
                'State',
                'PID/Program',
                'Process Name',
            ];

            $result = [];
            foreach ($lines as $row => $line) {
                $column = array_values(array_filter(explode(' ', $line), 'strlen'));
                foreach ($columns as $col => $key) {
                    $result[$row][$key] = @$column[$col];
                }
            }
            $result = array_values($result);
        }
        
        return $result;
    }

    /**
     * Get system architecture
     *
     * @return string
     */
    public function arch()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $cpu=  $wmi->ExecQuery("Select * from Win32_Processor");

            foreach ($cpu as $c) {
                $arch = '32-bit';
                $cpu_arch = $c->AddressWidth;

                if ($cpu_arch != 32) {
                    $os = $wmi->ExecQuery("Select * from Win32_OperatingSystem");

                    foreach ($os as $o) {
                        if ($o->Version >= 6.0) {
                            $arch = objItem.OSArchitecture;
                        }
                    }
                }
            }
        } else {
            $arch = shell_exec('arch');
        }
        return trim($arch);
    }

    /**
     * Get system hostname
     *
     * @return string
     */
    public function hostname()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $computer = $wmi->ExecQuery("SELECT * FROM Win32_ComputerSystem");

            foreach ($computer as $c) {
                $hostname = $c->Name;
            }
        } else {
            $hostname = shell_exec('hostname');
        }
        return trim($hostname);
    }

    /**
     * Get system last logins
     *
     * @return string
     */
    public function logins($parse = true)
    {
        $result = trim(shell_exec('last'));

        if ($parse) {
            $lines = explode(PHP_EOL, $result);

            // detect end by empty line space
            $end = 0;
            foreach ($lines as $no => $line) {
                if (trim($line) == '') {
                    $end = $no;
                    break;
                }
            }
            // filter out end lines
            foreach (range($end, count($lines)) as $key) {
                unset($lines[$key]);
            }

            // define columns
            $columns = [
                'User',
                'Terminal',
                'Display',
                'Day',
                'Month',
                'Day Date',
                'Day Time',
                '-',
                'Disconnected',
                'Duration',
            ];

            // generic match rows for columns and set into return
            $result = [];
            foreach ($lines as $row => $line) {
                $column = array_values(array_filter(explode(' ', $line), 'strlen'));
                foreach ($columns as $col => $key) {
                    $result[$row][$key] = @$column[$col];
                }
            }

            // fix
            $fix = [];
            foreach ($result as $key => $row) {
                if ($row['User'] == 'reboot') {
                    $fix[] = [
                        'User' => 'Reboot',
                        'Terminal' => '',
                        'Date' => '',
                        'Disconnected' => '',
                        'Duration' => '',
                    ];
                } else {
                    if ($row['Duration'] == 'no') {
                        $row['Duration'] = '';
                    }
                    if ($row['Disconnected'] == '-') {
                        $row['Disconnected'] = '';
                    }

                    $fix[] = [
                        'User' => $row['User'],
                        'Terminal' => $row['Terminal'],
                        'Display' => $row['Display'],
                        'Date' => $row['Day'].' '.$row['Month'].' '.$row['Day Date'].' '.$row['Day Time'],
                        'Disconnected' => $row['Disconnected'],
                        'Duration' => trim($row['Duration'], '()'),
                    ];
                }
            }
            $result = $fix;
        }
        
        return $result;
    }

    /**
     * Get system process tree
     *
     * @return string
     */
    public function pstree()
    {
        return trim(shell_exec('pstree'));
    }

    /**
     * Get system top output
     *
     * @param string
     */
    public function top($parse = true)
    {
        if (!file_exists($this->tmp_path.'/system')) {
            mkdir($this->tmp_path.'/system', 0755, true);
        }
        shell_exec('top -n 1 -b > '.$this->tmp_path.'/system/top-output');
        usleep(25000);
        $result = trim(file_get_contents($this->tmp_path.'/system/top-output'));

        if ($parse) {
            $lines = explode(PHP_EOL, $result);

            // detect start by empty line space
            $start = 0;
            foreach ($lines as $no => $line) {
                if (trim($line) == '') {
                    $start = $no;
                    break;
                }
            }
            // filter out header lines
            foreach (range(0, $start) as $key) {
                unset($lines[$key]);
            }

            //remove column line
            unset($lines[$start+1]);

            // define columns
            $columns = [
                'PID',
                'USER',
                'PR',
                'NI',
                'VIRT',
                'RES',
                'SHR',
                'S',
                '%CPU',
                '%MEM',
                'TIME+',
                'COMMAND'
            ];

            // match rows for columns and set into return
            $result = [];
            foreach ($lines as $row => $line) {
                $column = array_values(array_filter(explode(' ', $line), 'strlen'));
                foreach ($columns as $col => $key) {
                    $result[$row][$key] = @$column[$col];
                }
            }
            $result = array_values($result);
        }
        return $result;
    }

    /**
     * Get system name/kernel version
     *
     * @return string
     */
    public function uname()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $os=  $wmi->ExecQuery("Select * from Win32_OperatingSystem");

            foreach ($os as $o) {
                $osname = explode('|', $o->Name);
                $uname = $osname[0].' '.$o->Version;
            }
        } else {
            $uname = shell_exec('uname -rs');
        }
        return trim($uname);
    }

    /**
     * Get system CPU info
     */
    public function cpu_info($parse = true)
    {
        $lines = trim(shell_exec('lscpu'));
        
        if (!$parse) {
            return $lines;
        }
        
        if (empty($lines)) {
            return [];
        }
        
        $lines = explode(PHP_EOL, $lines);
        
        $return = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            $return[trim($parts[0])] = trim($parts[1]);
        }

        return $return;
    }

    /**
     * Get current network usage - Bit slow and not reliable
     */
    // public function netusage($direction = 'tx')
    // {
    //     $direction = $direction[0];

    //     if ($direction == 'tx') {
    //         return shell_exec('S=2; F=/sys/class/net/eth0/statistics/tx_bytes; X=`cat $F`; sleep $S; Y=`cat $F`; BPS="$(((Y-X)/S))"; echo $BPS');
    //     }
    //     if ($direction == 'rx') {
    //         return shell_exec('S=2; F=/sys/class/net/eth0/statistics/rx_bytes; X=`cat $F`; sleep $S; Y=`cat $F`; BPS="$(((Y-X)/S))"; echo $BPS');
    //     }
    // }

    /**
     * Get system load avarages and process count/last pid
     */
    public function load($parse = true)
    {
        $result = trim(shell_exec('cat /proc/loadavg'));
        
        if (!$parse) {
            return $result;
        }
        
        // break into parts
        $parts = explode(' ', $result);
        
        // current/total processes
        $procs = explode('/', isset($parts[3]) ? trim($parts[3]) : '0/0');

        return [
            '1m'        => isset($parts[0]) ? number_format(trim($parts[0]), 2) : '0.00',
            '5m'        => isset($parts[1]) ? number_format(trim($parts[1]), 2) : '0.00',
            '15m'       => isset($parts[2]) ? number_format(trim($parts[2]), 2) : '0.00',
            'curr_proc' => $procs[0],
            'totl_proc' => $procs[1],
            'last_pid'  => isset($parts[4]) ? trim($parts[4]) : 0
        ];
    }

    /**
     * Get disk file system table
     *
     * @return string
     */
    public function disks($parse = true)
    {
        if ($this->host_os !== 'WINDOWS') {
            $result = shell_exec('df -h --output=source,fstype,size,used,avail,pcent,target -x tmpfs -x devtmpfs');
        } else {
            $result = '';
        }

        if ($parse) {
            if (empty($result)) {
                return [];
            }

            $lines = explode(PHP_EOL, trim($result));
            unset($lines[0]);

            $columns = [
                'Filesystem',
                'Type',
                'Size',
                'Used',
                'Avail',
                'Used (%)',
                'Mounted'
            ];

            $result = [];
            foreach ($lines as $row => $line) {
                $column = array_values(array_filter(explode(' ', $line), 'strlen'));
                foreach ($columns as $col => $key) {
                    $result[$row][$key] = @$column[$col];
                }
            }
            $result = array_values($result);
        }
        return $result;
    }

    /**
     * Get system uptime
     */
    public function uptime($option = '-p')
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new \COM("winmgmts:\\\\.\\root\\cimv2");
            $os = $wmi->ExecQuery("SELECT * FROM Win32_OperatingSystem");

            foreach ($os as $o) {
                $date = explode('.', $o->LastBootUpTime);

                $uptime_date = DateTime::createFromFormat('YmdHis', $date[0]);
                $now = DateTime::createFromFormat('U', time());
                $interval = $uptime_date->diff($now);
                $uptime = $interval->format('up %a days, %h hours, %i minutes');
            }
        } else {
            $uptime = shell_exec('uptime '.$option);
        }
        return trim($uptime);
    }

    /**
     * Ping a server and return timing
     *
     * @return float
     */
    public function ping($host = '', $port = 80)
    {
        $start  = microtime(true);
        $file   = @fsockopen($host, $port, $errno, $errstr, 5);
        $stop   = microtime(true);
        $status = 0;

        if (!$file) {
            $status = -1;
        } else {
            fclose($file);
            $status = round((($stop - $start) * 1000), 2);
        }
        return $status;
    }

    /**
     * Get system distro
     *
     * @return string
     */
    public function distro()
    {
        if (file_exists('/etc/redhat-release')) {
            $centos_array = explode(' ', file_get_contents('/etc/redhat-release'));
            return strtoupper($centos_array[0]);
        }

        if (file_exists('/etc/os-release')) {
            preg_match('/ID=([a-zA-Z]+)/', file_get_contents('/etc/os-release'), $matches);
            return strtoupper($matches[1]);
        }
        return false;
    }
    
    /**
     * Execute command
     */
    public function shell_exec($cmd = '')
    {
        return shell_exec($cmd);
    }

    /**
     * Drop memory caches
     *
     * @requires root
     * @return void
     */
    public function drop_cache()
    {
        shell_exec('echo 1 > /proc/sys/vm/drop_caches');
        return true;
    }

    /**
     * Clear swapspace
     *
     * @requires root
     * @return void
     */
    public function clear_swap()
    {
        shell_exec('swapoff -a');
        shell_exec('swapon -a');
        return true;
    }

    /**
     * Reboot the system
     *
     * @requires root
     * @return void
     */
    public function reboot()
    {
        if (!file_exists($this->tmp_path.'/reboot.sh')) {
            file_put_contents($this->tmp_path.'/reboot.sh', '#!/bin/bash'.PHP_EOL.'/sbin/shutdown -r now');
            chmod($this->tmp_path.'/reboot.sh', 0750);
        }
        shell_exec($this->tmp_path.'/reboot.sh');
        return true;
    }
}
