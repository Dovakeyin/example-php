<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); 
header('Content-Type: text/plain; charset=utf-8');
date_default_timezone_set("Asia/Shanghai");

// 核心配置
const CONFIG = [
    'upstream' => ['http://198.16.100.186:8278/', 'http://50.7.92.106:8278/', 'http://50.7.234.10:8278/', 
                  'http://50.7.220.170:8278/', 'http://67.159.6.34:8278/'],
    'list_url' => 'https://cdn.jsdelivr.net/gh/hostemail/cdn@main/live/smart.txt',
    'backup_url' => 'https://tv.alishare.cf/live/smart.txt',
    'token_ttl' => 2400,
    'cache_ttl' => 3600,
    'fallback' => 'http://vjs.zencdn.net/v/oceans.mp4',
    'clear_key' => 'leifeng',
    'cache_dir' => __DIR__ . '/cache/',
    'file_cache_ttl' => 3600,
    'logo_cache_ttl' => 86400
];

// 通用函数
function getBaseUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]";
}

function getUpstream() {
    static $index = 0;
    return CONFIG['upstream'][$index++ % count(CONFIG['upstream'])];
}

function getChannelLogo($name) {
    if (empty($name)) return "https://cdn.jsdelivr.net/gh/hostemail/cdn@main/images/leifeng.png";
    $name = preg_replace(['/\s*_?HD$/i', '/\s*高清$/u', '/\s*$HD$$/i'], '', $name);
    $name = preg_replace('/[^\p{L}\p{N}\-]/u', '', trim(preg_replace('/\s+/u', '', $name), '-'));
    return $name ? "https://epg.v1.mk/logo/$name.png" : "https://cdn.jsdelivr.net/gh/hostemail/cdn@main/images/leifeng.png";
}

// 缓存处理
function clearCache() {
    $validKey = $_GET['key'] ?? '';
    if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) && !hash_equals(CONFIG['clear_key'], $validKey)) {
        header('HTTP/1.1 403 Forbidden');
        exit("权限验证失败\nIP: {$_SERVER['REMOTE_ADDR']}\n密钥状态: ".(empty($validKey)?'未提供':'无效'));
    }

    $results = [];
    $hasAPCu = extension_loaded('apcu') && ini_get('apcu.enabled');
    $cacheType = $hasAPCu ? 'APCu' : '文件缓存';
    
    if ($hasAPCu) {
        $results[] = apcu_clear_cache() ? '✅ APCu缓存已清除' : '❌ APCu清除失败';
    } else {
        $results[] = '⚠️ APCu扩展未安装，使用文件缓存';
    }

    $fileCount = 0;
    if (is_dir(CONFIG['cache_dir'])) {
        foreach (glob(CONFIG['cache_dir'].'*.cache') as $file) {
            if (unlink($file)) $fileCount++;
        }
    }
    $results[] = $fileCount ? "✅ 已清除 $fileCount 个文件缓存" : '⚠️ 文件缓存目录'.(is_dir(CONFIG['cache_dir'])?'为空':'不存在');

    try {
        $list = getChannelList(true);
        $results[] = '📡 频道列表已重建 数量:'.count($list)."\n🔧 使用缓存类型: $cacheType";
    } catch (Exception $e) {
        $results[] = '⚠️ 列表重建失败: '.$e->getMessage();
    }
    exit(implode("\n", $results));
}

function getChannelList($force = false) {
    $cacheKey = 'smart_channels';
    
    // 确保缓存目录存在
    if (!is_dir(CONFIG['cache_dir']) && !mkdir(CONFIG['cache_dir'], 0755, true)) {
        error_log("无法创建缓存目录: " . CONFIG['cache_dir']);
    }
    
    // 尝试获取缓存
    if (!$force) {
        $hasAPCu = extension_loaded('apcu') && ini_get('apcu.enabled');
        if ($hasAPCu && ($data = apcu_fetch($cacheKey))) {
            return $data;
        }
        if ($data = getFileCache($cacheKey)) {
            error_log("使用文件缓存: " . CONFIG['cache_dir'] . md5($cacheKey) . '.cache');
            return $data;
        }
    }
    
    // 获取原始数据
    $raw = fetchUrl(CONFIG['list_url']) ?: fetchUrl(CONFIG['backup_url']);
    if (!$raw) throw new Exception("所有数据源均不可用");

    $list = parseChannelData($raw);
    //$preprocessed = preprocessChannelList($list);
    $preprocessed = $list;
    
    // 存储缓存
    if (extension_loaded('apcu') && ini_get('apcu.enabled')) {
        apcu_store($cacheKey, $preprocessed, CONFIG['cache_ttl']);
    } else {
        setFileCache($cacheKey, $preprocessed);
    }
    return $preprocessed;
}

function getFileCache($key) {
    $file = CONFIG['cache_dir'].md5($key).'.cache';
    if (!file_exists($file)) return false;
    
    $data = @file_get_contents($file);
    if ($data === false) return false;
    
    $cache = @unserialize($data);
    if ($cache === false) return false;
    
    if (isset($cache['expire']) && $cache['expire'] < time()) {
        @unlink($file);
        return false;
    }
    
    return $cache['data'] ?? false;
}

function setFileCache($key, $data) {
    $file = CONFIG['cache_dir'].md5($key).'.cache';
    $tmp = $file.'.'.uniqid();
    $cache = ['data' => $data, 'expire' => time() + CONFIG['file_cache_ttl']];
    
    if (file_put_contents($tmp, serialize($cache)) !== false) {
        return rename($tmp, $file);
    }
    return false;
}

// 频道数据处理
function parseChannelData($raw) {
    $list = []; $group = '默认分组'; $seenIds = [];
    foreach (array_filter(explode("\n", trim($raw))) as $line) {
        if (strpos($line, '#genre#') !== false) {
            $group = trim(str_replace(',#genre#', '', $line));
            continue;
        }
        
        $parts = explode(',', $line, 2);
        if (count($parts) < 2) continue;
        
        $name = trim($parts[0]);
        $url = trim($parts[1]);
        
        // 处理频道名称
        if (strpos($name, '|') !== false) {
            $name = trim(explode('|', $name)[1] ?? '');
        }
        $name = preg_replace(['/\s*backup\s*/i', '/[^\p{L}\p{N}\s\-+]/u'], '', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (!$name) continue;
        
        // 提取ID
        $id = null;
        if (preg_match('/\/\/:id=([\w-]+)/', $url, $m) || preg_match('/[?&]id=([^&]+)/', $url, $m)) {
            $id = $m[1];
        } elseif (preg_match('/^id=([\w-]+)/', $url, $m)) {
            $id = $m[1];
        } elseif (preg_match('/^[\w-]+$/', $url)) {
            $id = $url;
        }
        
        if ($id && !isset($seenIds[$id])) {
            $list[] = ['id'=>$id, 'name'=>$name, 'group'=>$group, 'logo'=>getChannelLogo($name)];
            $seenIds[$id] = true;
        }
    }
    return $list ?: throw new Exception("频道列表解析失败");
}

function preprocessChannelList($list) {
    usort($list, function($a, $b) {
        return strcmp($a['group'], $b['group']) ?: strcmp($a['name'], $b['name']);
    });
    return $list;
}

// 频道请求处理
function handleChannelRequest() {
    $channelId = $_GET['id'];
    $tsFile = $_GET['ts'] ?? '';
    $token = manageToken();

    if ($tsFile) {
        proxyTS($channelId, $tsFile);
    } else {
        generateM3U8($channelId, $token);
    }
}

function proxyTS($id, $ts) {
    $url = getUpstream() . "$id/$ts";
    $data = fetchUrl($url);
    
    if ($data === null) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    
    header('Content-Type: video/MP2T');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

function generateM3U8($id, $token) {
    $upstream = getUpstream();
    $authUrl = $upstream . "$id/playlist.m3u8?" . http_build_query([
        'tid' => 'mc42afe745533',
        'ct' => intval(time() / 150),
        'tsum' => md5("tvata nginx auth module/$id/playlist.m3u8mc42afe745533" . intval(time() / 150))
    ]);
    
    $content = fetchUrl($authUrl);
    if (strpos($content, '404 Not Found') !== false) {
        header("Location: " . CONFIG['fallback']);
        exit();
    }
    
    $baseUrl = getBaseUrl() . '/' . basename(__FILE__);
    $content = preg_replace_callback('/(\S+\.ts)/', function($m) use ($baseUrl, $id, $token) {
        return "$baseUrl?id=" . urlencode($id) . "&ts=" . urlencode($m[1]) . "&token=" . urlencode($token);
    }, $content);
    
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Content-Disposition: inline; filename="' . $id . '.m3u8"');
    echo $content;
    exit;
}

function manageToken() {
    $token = $_GET['token'] ?? '';
    
    // 验证现有Token
    if (!empty($token) && validateToken($token)) {
        return $token;
    }
    
    // 生成新Token
    $newToken = bin2hex(random_bytes(16)) . ':' . time();
    
    // TS请求重定向
    if (isset($_GET['ts'])) {
        header("Location: " . getBaseUrl() . '/' . basename(__FILE__) . '?' . http_build_query([
            'id' => $_GET['id'],
            'ts' => $_GET['ts'],
            'token' => $newToken
        ]));
        exit();
    }
    
    return $newToken;
}

function validateToken($token) {
    $parts = explode(':', $token);
    if (count($parts) !== 2) return false;
    
    $timestamp = (int)$parts[1];
    return (time() - $timestamp) <= CONFIG['token_ttl'];
}

// 主列表生成
function GenerateChannelList() {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");

    try {
        $channels = getChannelList();
        $type = strtolower($_GET['type'] ?? 'm3u');
        $scriptName = basename(__FILE__); 
        
        if ($type === 'txt') {
            $output = '';
            $currentGroup = '';
            $firstGroup = true;
            
            foreach ($channels as $chan) {
                if ($chan['group'] !== $currentGroup) {
                    if (!$firstGroup) {
                        $output .= "\n";
                    } 
                    
                    $output .= $chan['group'] . ",#genre#\n";
                    $currentGroup = $chan['group'];
                    $firstGroup = false;
                }
                $output .= $chan['name'] . "," . getBaseUrl() . "/" . $scriptName . "?" . http_build_query(['id' => $chan['id']]) . "\n";
            }
            echo trim($output);
        } else {
            echo "#EXTM3U\n";
            $currentGroup = '';
            foreach ($channels as $chan) {
                if ($chan['group'] !== $currentGroup) {
                    echo "#EXTINF:-1 group-title=\"{$chan['group']}\", tvg-name=\"====={$chan['group']}=====\", ===== {$chan['group']} =====\n";
                    echo  CONFIG['fallback']. "\n"; // 虚拟URL用于显示分组
                    $currentGroup = $chan['group'];
                }
                echo "#EXTINF:-1 tvg-id=\"{$chan['id']}\" tvg-name=\"{$chan['name']}\" group-title=\"{$chan['group']}\" tvg-logo=\"{$chan['logo']}\",{$chan['name']}\n";
                echo getBaseUrl() . "/". $scriptName. "?" . http_build_query(['id' => $chan['id']]) . "\n";
            }
        }
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        exit("无法获取频道列表: " . $e->getMessage());
    }
    exit;
}

// 网络请求
function fetchUrl($url, $retry = 3) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']
    ]);
    
    while ($retry-- > 0) {
        $data = curl_exec($ch);
        if ($data !== false) break;
        usleep(500000 * (3 - $retry)); // 指数退避
    }
    
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 200 ? $data : false;
}

// 主路由
try {
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        clearCache();
    } elseif (!isset($_GET['id'])) {
        GenerateChannelList();
    } else {
        handleChannelRequest();
    }
} catch (Exception $e) {
    header('HTTP/1.1 503 Service Unavailable');
    exit("系统维护中，请稍后重试\n错误详情：" . $e->getMessage());
}
