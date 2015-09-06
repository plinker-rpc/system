<?php
namespace plinker\System;

class System {

    public function __construct(array $config = array())
    {
        $this->config = $config;
        $this->host_os = trim(strtoupper(strstr(php_uname(),' ',true)));
    }

    function get_system_updates()
    {
        // allow only run once (though maybe twice) a day at 6 am
        if (file_exists('./check-updates') || (date('G') == '6' && date('i') >= 0 && date('i') < 30)) {
            unlink('./check-updates');
            if ($this->host_os === 'WINDOWS') {
                $updSess = new COM("Microsoft.Update.Session");
                $updSrc = $updSess->CreateUpdateSearcher();
                $result = $updSrc->Search('IsInstalled=0 and Type=\'Software\' and IsHidden=0');
                return !empty($result->Updates->Count) ? '1':'0';
            } else {
                if (get_distro() === 'UBUNTU') {
                    $get_updates = shell_exec('apt-get -s dist-upgrade');

                    if (preg_match('/^(\d+).+upgrade.+(\d+).+newly\sinstall/m', $get_updates, $matches)) {
                        $result = (int) $matches[1] + (int) $matches[2];
                    } else {
                        $result = 0;
                    }
                    return !empty($result) ? '1':'0';
                }
                if (get_distro() === 'CENTOS') {
                    exec('yum check-update', $output, $exitCode);
                    return ($exitCode == 100) ? '1':'0';
                }
                return '-1';
            }

        } else {
            return '-1';
        }
    }

    function get_disk_space($path = '/')
    {
        $path = $path[0];

        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
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

    function get_total_disk_space($path = '/')
    {
        $path = $path[0];

        $ds = 0;
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
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

    function get_memory_stats()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
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
            $fh = fopen('/proc/meminfo','r');

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

    function get_memory_total()
    {
        $mem_total = 0;
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
            $os =  $wmi->ExecQuery("SELECT * FROM Win32_OperatingSystem");

            foreach ($os as $m) {
                $mem_total = $m->TotalVisibleMemorySize;
            }
        } else {
            $fh = fopen('/proc/meminfo','r');

            while ($line = fgets($fh)) {
                $pieces = array();
                if (preg_match('/^MemTotal:\s+(\d+)\skB$/', $line, $pieces)) {
                    $mem_total = $pieces[1];
                }
            }
            fclose($fh);
        }

        return $mem_total;
    }

    function server_cpu_usage()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
            $cpus = $wmi->ExecQuery("SELECT LoadPercentage FROM Win32_Processor");

            foreach ($cpus as $cpu) {
                $return = $cpu->LoadPercentage;
            }
        } else {
            $return = shell_exec('top -d 0.5 -b -n2 | grep "Cpu(s)"|tail -n 1 | awk \'{print $2 + $4}\'');
        }
        return trim($return);
    }

    function get_machine_id()
    {
        if (file_exists('./machine-id')) {
            return file_get_contents('./machine-id');
        }

        if (file_exists('/var/lib/dbus/machine-id')) {
            $id = trim(`cat /var/lib/dbus/machine-id`);
            file_put_contents('./machine-id', $id);
            return $id;
        }

        if (file_exists('/etc/machine-id')) {
            $id = trim(`cat /etc/machine-id`);
            file_put_contents('./machine-id', $id);
            return $id;
        }

        $id = sha1(uniqid(true));
        file_put_contents('./machine-id', $id);
        return $id;
    }

    function netstat($option = '-ant')
    {
        $option = $option[0];

        return shell_exec('netstat '.$option);
    }

    function arch()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
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
        return $arch;
    }

    function hostname()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
            $computer = $wmi->ExecQuery("SELECT * FROM Win32_ComputerSystem");

            foreach ($computer as $c) {
                $hostname = trim($c->Name);
            }
        } else {
            $hostname = shell_exec('hostname');
        }
        return $hostname;
    }

    function logins()
    {
        return shell_exec('last');
    }

    function pstree()
    {
        return shell_exec('pstree');
    }

    function top()
    {
        if (date('i') >= 0 && date('i') < 10) {
            return '-1';
        }

        shell_exec('top -n 1 -b > ./top-output');
        $result = file_get_contents('./top-output');
        unlink('./top-output');
        return trim($result);
    }

    function uname()
    {
        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
            $os=  $wmi->ExecQuery("Select * from Win32_OperatingSystem");

            foreach ($os as $o) {
                $osname = explode('|', $o->Name);
                $uname = $osname[0].' '.$o->Version;
            }
        } else {
            $uname = shell_exec('uname -rs');
        }
        return $uname;
    }

    function cpuinfo()
    {
        return trim(shell_exec('cat /proc/cpuinfo'));
    }

    function netusage($direction = 'tx')
    {
        $direction = $direction[0];

        if ($direction == 'tx') {
            return shell_exec('S=2; F=/sys/class/net/eth0/statistics/tx_bytes; X=`cat $F`; sleep $S; Y=`cat $F`; BPS="$(((Y-X)/S))"; echo $BPS');
        }
        if ($direction == 'rx') {
            return shell_exec('S=2; F=/sys/class/net/eth0/statistics/rx_bytes; X=`cat $F`; sleep $S; Y=`cat $F`; BPS="$(((Y-X)/S))"; echo $BPS');
        }
    }

    function load()
    {
        return shell_exec('cat /proc/loadavg');
    }

    function disks()
    {
        if ($this->host_os !== 'WINDOWS') {
            return shell_exec('df -h --output=source,fstype,size,used,avail,pcent,target -x tmpfs -x devtmpfs');
        } else {
            return '';
        }
    }

    function uptime($option = '-p')
    {
        $option = $option[0];

        if ($this->host_os === 'WINDOWS') {
            $wmi = new COM("winmgmts:\\\\.\\root\\cimv2");
            $os = $wmi->ExecQuery("SELECT * FROM Win32_OperatingSystem");

            foreach ($os as $o) {
                $date = explode('.', $o->LastBootUpTime);

                $uptime_date = DateTime::createFromFormat('YmdHis', $date[0]);
                $now = DateTime::createFromFormat('U', time());
                $interval = $uptime_date->diff($now);
                $uptime = $interval->format('up %a days, %h hours, %i minutes');
            }
        } else {
            $uptime = trim(shell_exec('uptime '.$option));
        }
        return $uptime;
    }

    function ping($host = '')
    {
        $host = $host[0];

        $start  = microtime(true);
        $file   = @fsockopen($host, 80, $errno, $errstr, 5);
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

    function get_distro()
    {
        if (file_exists('/etc/redhat-release')) {
            $centos_array = explode(' ', file_get_contents('/etc/redhat-release'));
            return strtoupper($centos_array[0]);
        }

        if (file_exists('/etc/os-release')) {
            preg_match('/ID=([a-zA-Z]+)/', file_get_contents('/etc/os-release'), $matches);
            return strtoupper($matches[1]);
        }
    }

    function drop_cache()
    {
        shell_exec('echo 1 > /proc/sys/vm/drop_caches');
    }

    function clear_swap()
    {
        shell_exec('swapoff -a');
        shell_exec('swapon -a');
    }

    function reboot()
    {
        if (!file_exists('./reboot.sh')) {
            file_put_contents('./reboot.sh', '#!/bin/bash'.PHP_EOL.'/sbin/shutdown -r now');
            chmod('./reboot.sh', 0750);
        }
        shell_exec('./reboot.sh');
    }

    function check_updates()
    {
        file_put_contents('./check-updates', '1');
        chmod('./check-updates', 0750);
    }

}