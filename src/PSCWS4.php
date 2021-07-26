<?php

namespace hulang;
/* ----------------------------------------------------------------------- *\
 PHP版简易中文分词第四版(PSCWS v4.0) - 分词核心类库代码
 -----------------------------------------------------------------------
 作者: 马明练(hightman) (MSN: MingL_Mar@msn.com) (php-QQ群: 17708754)
 网站: http://www.ftphp.com/scws/
 时间: 2007/05/20
 修订: 2008/12/20
 编辑: set number ; syntax on ; set autoindent ; set tabstop=4 (vim)
 -----------------------------------------------------------------------
 核心类的功能:

 这是 scws-1.0 (纯C实现) 的一个 PHP 实现方式, 算法和功能一样
 针对输入的字符串文本执行分词, 根据词典N-路径最大概率法分词.

 支持人名、地名、数字识别；能识别 .NET, C++, Q币 之类特殊词汇
 支持 UTF-8/GBK 编码, 特别为搜索引擎考量而支持长词再细分的复方分词法
 使用 UTF-8 可扩展到任何多字节语言分词(如日语，韩语等)

 用法(主要类方法, 与 scws 之 PHP 扩展版兼容用法):

 class PSCWS4 {
 void close(void);
 void set_charset(string charset);
 bool set_dict(string dict_path);
 void set_rule(string rule_path);
 void set_ignore(bool set);
 void set_multi(int level);
 void set_debug(bool set);
 void set_duality(bool set);

 void send_text(string text);
 mixed get_result(void);
 mixed get_tops( [int limit [, string attr]] );

 string version(void);
 };
 * ----------------------------------------------------------------------- */

define('PSCWS4_RULE_MAX', 31);
define('PSCWS4_RULE_SPECIAL', 0x80000000);
define('PSCWS4_RULE_NOSTATS', 0x40000000);
define('PSCWS4_ZRULE_NONE', 0x00);
define('PSCWS4_ZRULE_PREFIX', 0x01);
define('PSCWS4_ZRULE_SUFFIX', 0x02);
define('PSCWS4_ZRULE_INCLUDE', 0x04);
define('PSCWS4_ZRULE_EXCLUDE', 0x08);
define('PSCWS4_ZRULE_RANGE', 0x10);
define('PSCWS4_IGN_SYMBOL', 0x01);
define('PSCWS4_DEBUG', 0x02);
define('PSCWS4_DUALITY', 0x04);
define('PSCWS4_MULTI_NONE', 0x0000);
define('PSCWS4_MULTI_SHORT', 0x1000);
define('PSCWS4_MULTI_DUALITY', 0x2000);
define('PSCWS4_MULTI_ZMAIN', 0x4000);
define('PSCWS4_MULTI_ZALL', 0x8000);
define('PSCWS4_MULTI_MASK', 0xf000);
define('PSCWS4_ZIS_USED', 0x8000000);
define('PSCWS4_PFLAG_WITH_MB', 0x01);
define('PSCWS4_PFLAG_ALNUM', 0x02);
define('PSCWS4_PFLAG_VALID', 0x04);
define('PSCWS4_PFLAG_DIGIT', 0x08);
define('PSCWS4_PFLAG_ADDSYM', 0x10);
define('PSCWS4_WORD_FULL', 0x01);
define('PSCWS4_WORD_PART', 0x02);
define('PSCWS4_WORD_USED', 0x04);
define('PSCWS4_WORD_RULE', 0x08);
define('PSCWS4_ZFLAG_PUT', 0x02);
define('PSCWS4_ZFLAG_N2', 0x04);
define('PSCWS4_ZFLAG_NR2', 0x08);
define('PSCWS4_ZFLAG_WHEAD', 0x10);
define('PSCWS4_ZFLAG_WPART', 0x20);
define('PSCWS4_ZFLAG_ENGLISH', 0x40);
define('PSCWS4_ZFLAG_SYMBOL', 0x80);
define('PSCWS4_MAX_EWLEN', 16);
define('PSCWS4_MAX_ZLEN', 128);

class PSCWS4
{
    var $_xd;
    var $_rs;
    var $_rd;
    var $_cs = '';
    var $_ztab;
    var $_mode = 0;
    var $_txt;
    var $_res;
    var $_zis;
    var $_off = 0;
    var $_len = 0;
    var $_wend = 0;
    var $_wmap;
    var $_zmap;
    public function __construct($charset = 'utf8')
    {
        $this->_xd = false;
        $this->_rs = $this->_rd = array();
        $this->set_charset($charset);
    }
    public function __destruct()
    {
        $this->close();
    }
    public function set_charset($charset = 'utf8')
    {
        $charset = strtolower(trim($charset));
        if ($charset !== $this->_cs) {
            $this->_cs = $charset;
            $this->_ztab = array_fill(0, 0x81, 1);
            if ($charset == 'utf-8' || $charset == 'utf8') {
                $this->_ztab = array_pad($this->_ztab, 0xc0, 1);
                $this->_ztab = array_pad($this->_ztab, 0xe0, 2);
                $this->_ztab = array_pad($this->_ztab, 0xf0, 3);
                $this->_ztab = array_pad($this->_ztab, 0xf8, 4);
                $this->_ztab = array_pad($this->_ztab, 0xfc, 5);
                $this->_ztab = array_pad($this->_ztab, 0xfe, 6);
                $this->_ztab[] = 1;
            } else {
                $this->_ztab = array_pad($this->_ztab, 0xff, 2);
            }
            $this->_ztab[] = 1;
        }
    }
    public function set_dict($fpath)
    {
        $xdb = new XDB_R();
        if (!$xdb->Open($fpath)) {
            return false;
        }
        $this->_xd = $xdb;
    }
    public function set_rule($fpath)
    {
        $this->_rule_load($fpath);
    }
    public function set_ignore($yes)
    {
        if ($yes == true) {
            $this->_mode |= PSCWS4_IGN_SYMBOL;
        } else {
            $this->_mode &= ~PSCWS4_IGN_SYMBOL;
        }
    }
    public function set_multi($level)
    {
        $level = (intval($level) << 12);

        $this->_mode &= ~PSCWS4_MULTI_MASK;
        if ($level & PSCWS4_MULTI_MASK) {
            $this->_mode |= $level;
        }
    }
    public function set_debug($yes)
    {
        if ($yes == true) {
            $this->_mode |= PSCWS4_DEBUG;
        } else {
            $this->_mode &= ~PSCWS4_DEBUG;
        }
    }
    public function set_duality($yes)
    {
        if ($yes == true) {
            $this->_mode |= PSCWS4_DUALITY;
        } else {
            $this->_mode &= ~PSCWS4_DUALITY;
        }
    }
    public function send_text($text)
    {
        $this->_txt = (string)$text;
        $this->_len = strlen($this->_txt);
        $this->_off = 0;
    }
    public function get_result()
    {
        $off = $this->_off;
        $len = $this->_len;
        $txt = $this->_txt;
        $this->_res = array();
        while (($off < $len) && (ord($txt[$off]) <= 0x20)) {
            if ($txt[$off] == "\r" || $txt[$off] == "\n") {
                $this->_off = $off + 1;
                $this->_put_res($off, 0, 1, 'un');
                return $this->_res;
            }
            $off++;
        }
        if ($off >= $len) {
            return false;
        }
        $this->_off = $off;
        $ch = $txt[$off];
        $cx = ord($ch);
        if ($this->_char_token($ch)) {
            $this->_off++;
            $this->_put_res($off, 0, 1, 'un');
            return $this->_res;
        }
        $clen = $this->_ztab[$cx];
        $zlen = 1;
        $pflag = ($clen > 1 ? PSCWS4_PFLAG_WITH_MB : ($this->_is_alnum($cx) ? PSCWS4_PFLAG_ALNUM : 0));
        while (($off = ($off + $clen)) < $len) {
            $ch = $txt[$off];
            $cx = ord($ch);
            if ($cx <= 0x20 || $this->_char_token($ch)) {
                break;
            }
            $clen = $this->_ztab[$cx];
            if (!($pflag & PSCWS4_PFLAG_WITH_MB)) {
                if ($clen == 1) {
                    if (($pflag & PSCWS4_PFLAG_ALNUM) && !$this->_is_alnum($cx)) {
                        $pflag ^= PSCWS4_PFLAG_ALNUM;
                    }
                } else {
                    if (!($pflag & PSCWS4_PFLAG_ALNUM) || $zlen > 2) {
                        break;
                    }
                    $pflag |= PSCWS4_PFLAG_WITH_MB;
                }
            } else if (($pflag & PSCWS4_PFLAG_WITH_MB) && $clen == 1) {
                if (!$this->_is_alnum($cx)) {
                    break;
                }
                $pflag &= ~PSCWS4_PFLAG_VALID;
                for ($i = $off + 1; $i < ($off + 3); $i++) {
                    if (!isset($txt[$i])) {
                        continue;
                    }
                    $ch = $txt[$i];
                    $cx = ord($ch);
                    if (($i >= $len) || ($cx <= 0x20) || ($this->_ztab[$cx] > 1)) {
                        $pflag |= PSCWS4_PFLAG_VALID;
                        break;
                    }
                    if (!$this->_is_alnum($cx)) {
                        break;
                    }
                }
                if (!($pflag & PSCWS4_PFLAG_VALID)) {
                    break;
                }
                $clen += ($i - $off - 1);
            }
            if (++$zlen >= PSCWS4_MAX_ZLEN) break;
        }
        if (($ch = $off) > $len) {
            $off -= $clen;
        }
        if ($off <= $this->_off) {
            return false;
        } else if ($pflag & PSCWS4_PFLAG_WITH_MB) {
            $this->_msegment($off, $zlen);
        } else if (!($pflag & PSCWS4_PFLAG_ALNUM) || (($off - $this->_off) >= PSCWS4_MAX_EWLEN)) {
            $this->_ssegment($off);
        } else {
            $zlen = $off - $this->_off;
            $this->_put_res($this->_off, 2.5 * log($zlen), $zlen, 'en');
        }
        $this->_off = ($ch > $len ? $len : $off);
        if (count($this->_res) == 0) {
            return $this->get_result();
        }
        return $this->_res;
    }
    function get_tops($limit = 10, $xattr = '')
    {
        $ret = array();
        if (!$this->_txt) {
            return false;
        }
        $xmode = false;
        $attrs = array();
        if ($xattr != '') {
            if (substr($xattr, 0, 1) == '~') {
                $xattr = substr($xattr, 1);
                $xmode = true;
            }
            foreach (explode(',', $xattr) as $tmp) {
                $tmp = strtolower(trim($tmp));
                if (!empty($tmp)) {
                    $attrs[$tmp] = true;
                }
            }
        }
        $off = $this->_off;
        $this->_off = $cnt = 0;
        $list = array();

        while ($tmpa = $this->get_result()) {
            foreach ($tmpa as $tmp) {
                if ($tmp['idf'] < 0.2 || substr($tmp['attr'], 0, 1) == '#') {
                    continue;
                }
                if (count($attrs) > 0) {
                    if ($xmode == true && !isset($attrs[$tmp['attr']])) {
                        continue;
                    }
                    if ($xmode == false && isset($attrs[$tmp['attr']])) {
                        continue;
                    }
                }
                $word = strtolower($tmp['word']);
                if ($this->_rule_checkbit($word, PSCWS4_RULE_NOSTATS)) {
                    continue;
                }
                if (isset($list[$word])) {
                    $list[$word]['weight'] += $tmp['idf'];
                    $list[$word]['times']++;
                } else {
                    $list[$word] = array('word' => $tmp['word'], 'times' => 1, 'weight' => $tmp['idf'], 'attr' => $tmp['attr']);
                }
            }
        }
        $this->_off = $off;
        $cmp_func = function ($a, $b) {
            return ($b['weight'] > $a['weight'] ? 1 : -1);
        };
        usort($list, $cmp_func);
        if (count($list) > $limit) {
            $list = array_slice($list, 0, $limit);
        }
        return $list;
    }
    public function close()
    {
        if ($this->_xd) {
            $this->_xd->Close();
            $this->_xd = false;
        }
        $this->_rd = array();
        $this->_rs = array();
    }
    public function version()
    {
        return sprintf('PSCWS/4.0 - by hightman');
    }
    public function _rule_load($fpath)
    {
        if (!($fd = fopen($fpath, 'r'))) {
            return false;
        }
        $this->_rs = array();
        $i = $j = 0;
        while ($buf = fgets($fd, 512)) {
            if (substr($buf, 0, 1) != '[' || !($pos = strpos($buf, ']'))) {
                continue;
            }
            if ($pos == 1 || $pos > 15) {
                continue;
            }
            $key = strtolower(substr($buf, 1, $pos - 1));
            if (isset($this->_rs[$key])) {
                continue;
            }
            $item = array('tf' => 5.0, 'idf' => 3.5, 'attr' => 'un', 'bit' => 0, 'flag' => 0, 'zmin' => 0, 'zmax' => 0, 'inc' => 0, 'exc' => 0);
            if ($key == 'special') {
                $item['bit'] = PSCWS4_RULE_SPECIAL;
            } else if ($key == 'nostats') {
                $item['bit'] = PSCWS4_RULE_NOSTATS;
            } else {
                $item['bit'] = (1 << $j);
                $j++;
            }
            $this->_rs[$key] = $item;
            if (++$i >= PSCWS4_RULE_MAX) {
                break;
            }
        }
        rewind($fd);
        $rbl = false;
        unset($item);
        while ($buf = fgets($fd, 512)) {
            $ch = substr($buf, 0, 1);
            if ($ch == ';') continue;
            if ($ch == '[') {
                unset($item);
                if (($pos = strpos($buf, ']')) > 1) {
                    $key = strtolower(substr($buf, 1, $pos - 1));
                    if (isset($this->_rs[$key])) {
                        $rbl = true;
                        $item = &$this->_rs[$key];
                    }
                }
                continue;
            }
            if ($ch == ':') {
                $buf = substr($buf, 1);
                if (!($pos = strpos($buf, '='))) {
                    continue;
                }
                list($pkey, $pval) = explode('=', $buf, 2);
                $pkey = trim($pkey);
                $pval = trim($pval);
                if ($pkey == 'line') {
                    $rbl = (strtolower(substr($pval, 0, 1)) == 'n' ? false : true);
                } else if ($pkey == 'tf') {
                    $item['tf'] = floatval($pval);
                } else if ($pkey == 'idf') {
                    $item['idf'] = floatval($pval);
                } else if ($pkey == 'attr') {
                    $item['attr'] = $pval;
                } else if ($pkey == 'znum') {
                    if ($pos = strpos($pval, ',')) {
                        $item['zmax'] = intval(trim(substr($pval, $pos + 1)));
                        $item['flag'] |= PSCWS4_ZRULE_RANGE;
                        $pval = substr($pval, 0, $pos);
                    }
                    $item['zmin'] = intval($pval);
                } else if ($pkey == 'type') {
                    if ($pval == 'prefix') {
                        $item['flag'] |= PSCWS4_ZRULE_PREFIX;
                    }
                    if ($pval == 'suffix') {
                        $item['flag'] |= PSCWS4_ZRULE_SUFFIX;
                    }
                } else if ($pkey == 'include' || $pkey == 'exclude') {
                    $clude = 0;
                    foreach (explode(',', $pval) as $tmp) {
                        $tmp = strtolower(trim($tmp));
                        if (!isset($this->_rs[$tmp])) {
                            continue;
                        }
                        $clude |= $this->_rs[$tmp]['bit'];
                    }
                    if ($pkey == 'include') {
                        $item['inc'] |= $clude;
                        $item['flag'] |= PSCWS4_ZRULE_INCLUDE;
                    } else {
                        $item['exc'] |= $clude;
                        $item['flag'] |= PSCWS4_ZRULE_EXCLUDE;
                    }
                }
                continue;
            }
            if (!isset($item)) {
                continue;
            }
            $buf = trim($buf);
            if (empty($buf)) {
                continue;
            }
            if ($rbl) {
                $this->_rd[$buf] = &$item;
            } else {
                $len = strlen($buf);
                for ($off = 0; $off < $len;) {
                    $ord = ord(substr($buf, $off, 1));
                    $zlen = $this->_ztab[$ord];
                    if ($off + $zlen >= $len) {
                        break;
                    }
                    $zch = substr($buf, $off, $zlen);
                    $this->_rd[$zch] = &$item;
                    $off += $zlen;
                }
            }
        }
    }
    public function _rule_get($str)
    {
        if (!isset($this->_rd[$str])) {
            return false;
        }
        return $this->_rd[$str];
    }
    public function _rule_checkbit($str, $bit)
    {
        if (!isset($this->_rd[$str])) {
            return false;
        }
        $bit2 = $this->_rd[$str]['bit'];
        return ($bit & $bit2 ? true : false);
    }
    public function _rule_check($rule, $str)
    {
        if (($rule['flag'] & PSCWS4_ZRULE_INCLUDE) && !$this->_rule_checkbit($str, $rule['bit'])) {
            return false;
        }
        if (($rule['flag'] & PSCWS4_ZRULE_EXCLUDE) && $this->_rule_checkbit($str, $rule['bit'])) {
            return false;
        }
        return true;
    }
    public function _put_res($o, $i, $l, $a)
    {
        $word = substr($this->_txt, $o, $l);
        $item = array('word' => $word, 'off' => $o, 'idf' => $i, 'len' => $l, 'attr' => $a);
        $this->_res[] = $item;
    }
    public function _is_alnum($c)
    {
        return (($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122));
    }

    public function _is_alpha($c)
    {
        return (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122));
    }

    public function _is_ualpha($c)
    {
        return ($c >= 65 && $c <= 90);
    }

    public function _is_digit($c)
    {
        return ($c >= 48 && $c <= 57);
    }

    public function _no_rule1($f)
    {
        return (($f & (PSCWS4_ZFLAG_SYMBOL | PSCWS4_ZFLAG_ENGLISH)) || (($f & (PSCWS4_ZFLAG_WHEAD | PSCWS4_ZFLAG_NR2)) == PSCWS4_ZFLAG_WHEAD));
    }

    public function _no_rule2($f)
    {
        return $this->_no_rule1($f);
    }

    public function _char_token($c)
    {
        return ($c == '(' || $c == ')' || $c == '[' || $c == ']' || $c == '{' || $c == '}' || $c == ':' || $c == '"');
    }
    public function _dict_query($word)
    {
        if (!$this->_xd) {
            return false;
        }
        $value = $this->_xd->Get($word);
        if (!$value) {
            return false;
        }
        $tmp = unpack('ftf/fidf/Cflag/a3attr', $value);
        return $tmp;
    }
    public function _ssegment($end)
    {
        $start = $this->_off;
        $wlen = $end - $start;
        if ($wlen > 1) {
            $txt = strtoupper(substr($this->_txt, $start, $wlen));
            if ($this->_rule_checkbit($txt, PSCWS4_RULE_SPECIAL)) {
                $this->_put_res($start, 9.5, $wlen, 'nz');
                return;
            }
        }
        $txt = $this->_txt;
        if ($this->_is_ualpha(ord($txt[$start])) && $txt[$start + 1] == '.') {
            for ($ch = $start + 2; $ch < $end; $ch++) {
                if (!$this->_is_ualpha(ord($txt[$ch]))) {
                    break;
                }
                $ch++;
                if ($ch == $end || $txt[$ch] != '.') {
                    break;
                }
            }
            if ($ch == $end) {
                $this->_put_res($start, 7.5, $wlen, 'nz');
                return;
            }
        }
        while ($start < $end) {
            $ch = $txt[$start++];
            $cx = ord($ch);
            if ($this->_is_alnum($cx)) {
                $pflag = $this->_is_digit($cx) ? PSCWS4_PFLAG_DIGIT : 0;
                $wlen = 1;
                while ($start < $end) {
                    $ch = $txt[$start];
                    $cx = ord($ch);
                    if ($pflag & PSCWS4_PFLAG_DIGIT) {
                        if (!$this->_is_digit($cx)) {
                            if (($pflag & PSCWS4_PFLAG_ADDSYM) || $cx != 0x2e || !$this->_is_digit(ord($txt[$start + 1]))) {
                                break;
                            }
                            $pflag |= PSCWS4_PFLAG_ADDSYM;
                        }
                    } else {
                        if (!$this->_is_alpha($cx)) {
                            if (!isset($txt[$start + 1])) {
                                break;
                            }
                            if (($pflag & PSCWS4_PFLAG_ADDSYM) || $cx != 0x27 || !$this->_is_alpha(ord($txt[$start + 1]))) {
                                break;
                            }
                            $pflag |= PSCWS4_PFLAG_ADDSYM;
                        }
                    }
                    $start++;
                    if (++$wlen >= PSCWS4_MAX_EWLEN) {
                        break;
                    }
                }
                $this->_put_res($start - $wlen, 2.5 * log($wlen), $wlen, 'en');
            } else if (!($this->_mode & PSCWS4_IGN_SYMBOL)) {
                $this->_put_res($start - 1, 0, 1, 'un');
            }
        }
    }
    public function _get_zs($i, $j = -1)
    {
        if ($j == -1) {
            $j = $i;
        }
        return substr($this->_txt, $this->_zmap[$i]['start'], $this->_zmap[$j]['end'] - $this->_zmap[$i]['start']);
    }
    public function _mget_word($i, $j)
    {
        $wmap = $this->_wmap;
        if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WHEAD)) {
            return $i;
        }
        for ($r = $i, $k = $i + 1; $k <= $j; $k++) {
            if ($wmap[$i][$k] && ($wmap[$i][$k]['flag'] & PSCWS4_WORD_FULL)) {
                $r = $k;
            }
        }
        return $r;
    }
    public function _mset_word($i, $j)
    {
        $wmap = $this->_wmap;
        $zmap = $this->_zmap;
        $item = $wmap[$i][$j];
        if (($item == false) || (($this->_mode & PSCWS4_IGN_SYMBOL)
            && !($item['flag'] & PSCWS4_ZFLAG_ENGLISH) && $item['attr'] == 'un')) {
            return;
        }
        if ($this->_mode & PSCWS4_DUALITY) {
            $k = $this->_zis;
            if ($i == $j && !($item['flag'] & PSCWS4_ZFLAG_ENGLISH) && $item['attr'] == 'un') {
                $this->_zis = $i;
                if ($k < 0) {
                    return;
                }
                $i = ($k & ~PSCWS4_ZIS_USED);
                if (($i != ($j - 1)) || (!($k & PSCWS4_ZIS_USED) && $this->_wend == $i)) {
                    $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
                    if ($i != ($j - 1)) {
                        return;
                    }
                }
                $this->_zis |= PSCWS4_ZIS_USED;
            } else {
                if (($k >= 0) && (!($k & PSCWS4_ZIS_USED) || ($j > $i))) {
                    $k &= ~PSCWS4_ZIS_USED;
                    $this->_put_res($zmap[$k]['start'], $wmap[$k][$k]['idf'], $zmap[$k]['end'] - $zmap[$k]['start'], $wmap[$k][$k]['attr']);
                }
                if ($j > $i) {
                    $this->_wend = $j + 1;
                }
                $this->_zis = -1;
            }
        }
        $this->_put_res($zmap[$i]['start'], $item['idf'], $zmap[$j]['end'] - $zmap[$i]['start'], $item['attr']);
        if (($j - $i) > 1) {
            $m = $i;
            if ($this->_mode & PSCWS4_MULTI_SHORT) {
                while ($m < $j) {
                    $k = $m;
                    for ($n = $m + 1; $n <= $j; $n++) {
                        if ($n == $j && $m == $i) {
                            break;
                        }
                        $item = $wmap[$m][$n];
                        if ($item && ($item['flag'] & PSCWS4_WORD_FULL)) {
                            $k = $n;
                            $this->_put_res($zmap[$m]['start'], $item['idf'], $zmap[$n]['end'] - $zmap[$m]['start'], $item['attr']);
                            if (!($item['flag'] & PSCWS4_WORD_PART)) {
                                break;
                            }
                        }
                    }
                    if ($k == $m) {
                        if ($m == $i) {
                            break;
                        }
                        $item = $wmap[$m][$m];
                        $this->_put_res($zmap[$m]['start'], $item['idf'], $zmap[$m]['end'] - $zmap[$m]['start'], $item['attr']);
                    }
                    if (($m = ($k + 1)) == $j) {
                        $m--;
                        break;
                    }
                }
            }
            if ($this->_mode & PSCWS4_MULTI_DUALITY) {
                while ($m < $j) {
                    $this->_put_res($zmap[$m]['start'], $wmap[$m][$m]['idf'], $zmap[$m + 1]['end'] - $zmap[$m]['start'], $wmap[$m][$m]['attr']);
                    $m++;
                }
            }
        }
        if (($j > $i) && ($this->_mode & (PSCWS4_MULTI_ZMAIN | PSCWS4_MULTI_ZALL))) {
            if (($j - $i) == 1 && !$wmap[$i][$j]) {
                if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_PUT) {
                    $i++;
                } else {
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_PUT;
                }
                $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_PUT;
            }
            do {
                if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_PUT) {
                    continue;
                }
                if (!($this->_mode & PSCWS4_MULTI_ZALL) && !strchr("jnv", substr($wmap[$i][$i]['attr'], 0, 1))) {
                    continue;
                }
                $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
            } while (++$i <= $j);
        }
    }
    public function _mseg_zone($f, $t)
    {
        $weight = $nweight = 0.0;
        $wmap = &$this->_wmap;
        $zmap = $this->_zmap;
        $mpath = $npath = false;
        for ($x = $i = $f; $i <= $t; $i++) {
            $j = $this->_mget_word($i, $t);
            if ($j == $i || $j <= $x || (/* $i > $x && */($wmap[$i][$j]['flag'] & PSCWS4_WORD_USED))) {
                continue;
            }
            if ($i == $f && $j == $t) {
                $mpath = array($j - $i, 0xff);
                break;
            }
            if ($i != $f && ($wmap[$i][$j]['flag'] & PSCWS4_WORD_RULE)) {
                continue;
            }
            $wmap[$i][$j]['flag'] |= PSCWS4_WORD_USED;
            $nweight = $wmap[$i][$j]['tf'] * ($j - $i + 1);
            if ($i == $f) {
                $nweight *= 1.2;
            } else if ($j == $t) {
                $nweight *= 1.4;
            }
            if ($npath == false) {
                $npath = array_fill(0, $t - $f + 2, 0xff);
            }
            $x = 0;
            for ($m = $f; $m < $i; $m = $n + 1) {
                $n = $this->_mget_word($m, $i - 1);
                $nweight *= $wmap[$m][$n]['tf'] * ($n - $m + 1);
                $npath[$x++] = $n - $m;
                if ($n > $m) {
                    $wmap[$m][$n]['flag'] |= PSCWS4_WORD_USED;
                }
            }
            $npath[$x++] = $j - $i;
            for ($m = $j + 1; $m <= $t; $m = $n + 1) {
                $n = $this->_mget_word($m, $t);
                $nweight *= $wmap[$m][$n]['tf'] * ($n - $m + 1);
                $npath[$x++] = $n - $m;
                if ($n > $m) {
                    $wmap[$m][$n]['flag'] |= PSCWS4_WORD_USED;
                }
            }
            $npath[$x] = 0xff;
            $nweight /= pow($x - 1, 4);
            if ($this->_mode & PSCWS4_DEBUG) {
                printf("PATH by keyword = %s, (weight=%.4f):\n", $this->_get_zs($i, $j), $nweight);
                for ($x = 0, $m = $f; ($n = $npath[$x]) != 0xff; $x++) {
                    $n += $m;
                    echo $this->_get_zs($m, $n) . " ";
                    $m = $n + 1;
                }
                echo "\n--\n";
            }
            $x = $j;
            if ($nweight > $weight) {
                $weight = $nweight;
                $swap = $mpath;
                $mpath = $npath;
                $npath = $swap;
                unset($swap);
            }
        }
        if ($mpath == false) {
            return;
        }
        for ($x = 0, $m = $f; ($n = $mpath[$x]) != 0xff; $x++) {
            $n += $m;
            $this->_mset_word($m, $n);
            $m = $n + 1;
        }
    }
    public function _msegment($end, $zlen)
    {
        $this->_wmap = array_fill(0, $zlen, array_fill(0, $zlen, false));
        $this->_zmap = array_fill(0, $zlen, false);
        $wmap = &$this->_wmap;
        $zmap = &$this->_zmap;
        $txt = $this->_txt;
        $start = $this->_off;
        $this->_zis = -1;
        for ($i = 0; $start < $end; $i++) {
            $ch = $txt[$start];
            $cx = ord($ch);
            $clen = $this->_ztab[$cx];
            if ($clen == 1) {
                while ($start++ < $end) {
                    $cx = ord($txt[$start]);
                    if ($this->_ztab[$cx] > 1) {
                        break;
                    }
                    $clen++;
                }
                $wmap[$i][$i] = array('tf' => 0.5, 'idf' => 0, 'flag' => PSCWS4_ZFLAG_ENGLISH, 'attr' => 'un');
            } else {
                $query = $this->_dict_query(substr($txt, $start, $clen));
                if (!$query) {
                    $wmap[$i][$i] = array('tf' => 0.5, 'idf' => 0, 'flag' => 0, 'attr' => 'un');
                } else {
                    if (substr($query['attr'], 0, 1) == '#') {
                        $query['flag'] |= PSCWS4_ZFLAG_SYMBOL;
                    }
                    $wmap[$i][$i] = $query;
                }
                $start += $clen;
            }
            $zmap[$i] = array('start' => $start - $clen, 'end' => $start);
        }
        $zlen = $i;
        for ($i = 0; $i < $zlen; $i++) {
            $k = 0;
            for ($j = $i + 1; $j < $zlen; $j++) {
                $query = $this->_dict_query($this->_get_zs($i, $j));
                if (!$query) {
                    break;
                }
                $ch = $query['flag'];
                if ($ch & PSCWS4_WORD_FULL) {
                    $wmap[$i][$j] = $query;
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($k = $i + 1; $k <= $j; $k++) {
                        $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WPART;
                    }
                }
                if (!($ch & PSCWS4_WORD_PART)) {
                    break;
                }
            }
            if ($k--) {
                if ($k == ($i + 1)) {
                    if ($wmap[$i][$k]['attr'] == 'nr') {
                        $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_NR2;
                    }
                }
                if ($k < $j) {
                    $wmap[$i][$k]['flag'] ^= PSCWS4_WORD_PART;
                }
            }
        }
        if (count($this->_rd) > 0) {
            for ($i = 0; $i < $zlen; $i++) {
                if ($this->_no_rule1($wmap[$i][$i]['flag'])) {
                    continue;
                }
                $r1 = $this->_rule_get($this->_get_zs($i));
                if (!$r1) {
                    continue;
                }
                $clen = ($r1['zmin'] > 0 ? $r1['zmin'] : 1);
                if (($r1['flag'] & PSCWS4_ZRULE_PREFIX) && ($i < ($zlen - $clen))) {
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i + $ch;
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }
                    $j = $i + $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j++;
                    }
                    if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_NR2) {
                        if ($clen == 1) {
                            continue;
                        }
                        $wmap[$i][$i + 1]['flag'] |= PSCWS4_WORD_PART;
                    }
                    $k = $i + $clen;
                    $wmap[$i][$k] = array('tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => (PSCWS4_WORD_RULE | PSCWS4_WORD_FULL), 'attr' => $r1['attr']);
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $i + 1; $j <= $k; $j++) $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;

                    if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WPART)) {
                        $i = $k;
                        continue;
                    }
                }

                if (($r1['flag'] & PSCWS4_ZRULE_SUFFIX) && ($i >= $clen)) {
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i - $ch;
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }
                    $j = $i - $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j--;
                    }
                    $k = $i - $clen;
                    if ($wmap[$k][$i] != false) {
                        continue;
                    }
                    $wmap[$k][$i] = array('tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']);
                    $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $k + 1; $j <= $i; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                        if (($j != $i) && ($wmap[$k][$j] != false)) {
                            $wmap[$k][$j]['flag'] |= PSCWS4_WORD_PART;
                        }
                    }
                    continue;
                }
            }
            for ($i = $zlen - 2; $i >= 0; $i--) {
                if (($wmap[$i][$i + 1] == false) || ($wmap[$i][$i + 1]['flag'] & PSCWS4_WORD_PART)) {
                    continue;
                }
                $k = $i + 1;
                $r1 = $this->_rule_get($this->_get_zs($i, $k));
                if (!$r1) {
                    continue;
                }
                $clen = $r1['zmin'] > 0 ? $r1['zmin'] : 1;
                if (($r1['flag'] & PSCWS4_ZRULE_PREFIX) && ($k < ($zlen - $clen))) {
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $k + $ch;
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }
                    $j = $k + $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j++;
                    }
                    $k = $k + $clen;
                    $wmap[$i][$k] = array('tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']);
                    $wmap[$i][$i + 1]['flag'] |= PSCWS4_WORD_PART;
                    for ($j = $i + 2; $j <= $k; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                        $i--;
                        continue;
                    }
                }
                if (($r1['flag'] & PSCWS4_ZRULE_SUFFIX) && ($i >= $clen)) {
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i - $ch;
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }
                    $j = $i - $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j--;
                    }
                    $k = $i - $clen;
                    $i = $i + 1;
                    $wmap[$k][$i] = array('tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']);
                    $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $k + 1; $j <= $i; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                        if ($wmap[$k][$j] != false) {
                            $wmap[$k][$j]['flag'] |= PSCWS4_WORD_PART;
                        }
                    }
                    $i -= ($clen + 1);
                    continue;
                }
            }
        }
        for ($i = 0, $j = 0; $i < $zlen; $i++) {
            if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WPART) {
                continue;
            }
            if ($i > $j) {
                $this->_mseg_zone($j, $i - 1);
            }
            $j = $i;
            if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WHEAD)) {
                $this->_mset_word($i, $i);
                $j++;
            }
        }
        if ($i > $j) {
            $this->_mseg_zone($j, $i - 1);
        }
        if (($this->_mode & PSCWS4_DUALITY) && ($this->_zis >= 0) && !($this->_zis & PSCWS4_ZIS_USED)) {
            $i = $this->_zis;
            $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
        }
    }
}
