<?php

namespace hulang;
/* ----------------------------------------------------------------------- *\
 PHP版 简易中文分词第2/3版(SCWS v2/3) - xdb_r只读查询类
 -----------------------------------------------------------------------
 作者: 马明练(hightman) (MSN: MingL_Mar@msn.com) (php-QQ群: 17708754)
 网站: http://www.ftphp.com/scws/
 时间: 2007/05/30
 修订: 2008/12/20
 编辑: set number ; syntax on ; set autoindent ; set tabstop=4 (vim)
 $Id: xdb_r.class.php,v 1.1 2008/12/20 18:59:30 hightman Exp $

 目的: 取代 cdb/gdbm 快速存取分词词典, 因大部分用户缺少这些基础配件和知识
 用法: 参见 xdb.class.php 内的说明, 此处仅为读取举例, 无其它功能.
 ->Get($key)
 \* ----------------------------------------------------------------------- */

define('XDB_VERSION', 34);
define('XDB_TAGNAME', 'XDB');
define('XDB_MAXKLEN', 0xf0);

class XDB_R
{
    var $fd = false;
    var $hash_base = 0;
    var $hash_prime = 0;
    public function __construct()
    {
    }
    public function __destruct()
    {
        $this->Close();
    }
    public function Open($fpath)
    {
        $this->Close();
        if (!($fd = @fopen($fpath, 'rb'))) {
            trigger_error("XDB::Open(" . basename($fpath) . ") failed.", E_USER_WARNING);
            return false;
        }
        if (!$this->_check_header($fd)) {
            trigger_error("XDB::Open(" . basename($fpath) . "), invalid xdb format.", E_USER_WARNING);
            fclose($fd);
            return false;
        }
        $this->fd = $fd;
        return true;
    }
    public function Get($key)
    {

        if (!$this->fd) {
            trigger_error("XDB:Get(), null db handler.", E_USER_WARNING);
            return false;
        }
        $klen = strlen($key);
        if ($klen == 0 || $klen > XDB_MAXKLEN) {
            return false;
        }
        $rec = $this->_get_record($key);
        if (!isset($rec['vlen']) || $rec['vlen'] == 0) {
            return false;
        }
        return $rec['value'];
    }
    public function Close()
    {
        if (!$this->fd) {
            return;
        }
        fclose($this->fd);
        $this->fd = false;
    }
    public function _get_index($key)
    {
        $l = strlen($key);
        $h = $this->hash_base;
        while ($l--) {
            $h += ($h << 5);
            $h ^= ord($key[$l]);
            $h &= 0x7fffffff;
        }
        return ($h % $this->hash_prime);
    }
    public function _check_header($fd)
    {
        fseek($fd, 0, SEEK_SET);
        $buf = fread($fd, 32);
        if (strlen($buf) !== 32) {
            return false;
        }
        $hdr = unpack('a3tag/Cver/Ibase/Iprime/Ifsize/fcheck/a12reversed', $buf);
        if ($hdr['tag'] != XDB_TAGNAME) {
            return false;
        }
        $fstat = fstat($fd);
        if ($fstat['size'] != $hdr['fsize']) {
            return false;
        }
        $this->hash_base = $hdr['base'];
        $this->hash_prime = $hdr['prime'];
        $this->version = $hdr['ver'];
        $this->fsize = $hdr['fsize'];
        return true;
    }
    public function _get_record($key)
    {
        $this->_io_times = 1;
        $index = ($this->hash_prime > 1 ? $this->_get_index($key) : 0);
        $poff = $index * 8 + 32;
        fseek($this->fd, $poff, SEEK_SET);
        $buf = fread($this->fd, 8);
        if (strlen($buf) == 8) {
            $tmp = unpack('Ioff/Ilen', $buf);
        } else {
            $tmp = array('off' => 0, 'len' => 0);
        }
        return $this->_tree_get_record($tmp['off'], $tmp['len'], $poff, $key);
    }
    public function _tree_get_record($off, $len, $poff = 0, $key = '')
    {
        if ($len == 0) {
            return (array('poff' => $poff));
        }
        $this->_io_times++;
        fseek($this->fd, $off, SEEK_SET);
        $rlen = XDB_MAXKLEN + 17;
        if ($rlen > $len) {
            $rlen = $len;
        }
        $buf = fread($this->fd, $rlen);
        $rec = unpack('Iloff/Illen/Iroff/Irlen/Cklen', substr($buf, 0, 17));
        $fkey = substr($buf, 17, $rec['klen']);
        $cmp = ($key ? strcmp($key, $fkey) : 0);
        if ($cmp > 0) {
            unset($buf);
            return $this->_tree_get_record($rec['roff'], $rec['rlen'], $off + 8, $key);
        } else if ($cmp < 0) {
            unset($buf);
            return $this->_tree_get_record($rec['loff'], $rec['llen'], $off, $key);
        } else {
            $rec['poff'] = $poff;
            $rec['off'] = $off;
            $rec['len'] = $len;
            $rec['voff'] = $off + 17 + $rec['klen'];
            $rec['vlen'] = $len - 17 - $rec['klen'];
            $rec['key'] = $fkey;
            fseek($this->fd, $rec['voff'], SEEK_SET);
            $rec['value'] = fread($this->fd, $rec['vlen']);
            return $rec;
        }
    }
}
