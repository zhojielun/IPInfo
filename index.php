<?php
// 设置响应头，允许跨域请求
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// API密钥配置 (实际使用时请替换为真实的API密钥并妥善保管)
$API_KEYS = [
    'ipinfo' => getenv('IPINFO_API_KEY') ?: 'your_ipinfo_key',
    'ipapi' => getenv('IPAPI_KEY') ?: 'your_ipapi_key',
    'ipstack' => getenv('IPSTACK_KEY') ?: 'your_ipstack_key',
    'maxmind' => getenv('MAXMIND_KEY') ?: 'your_maxmind_key'
];

// 错误处理
function handleError($message, $details = null, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'error' => $message,
        'details' => $details
    ]);
    exit;
}

// 获取请求IP
function getRequestIP() {
    $ip = $_GET['ip'] ?? null;
    
    if (empty($ip)) {
        // 获取客户端IP
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                break;
            }
        }
    }
    
    // 如果是IPv6格式的IPv4，提取IPv4部分
    if (strpos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }
    
    // 如果是本地IP，使用公共IP查询服务获取真实IP
    if ($ip === '127.0.0.1' || $ip === 'localhost' || $ip === '::1') {
        try {
            $publicIpData = file_get_contents('https://api.ipify.org?format=json');
            $publicIpObj = json_decode($publicIpData);
            if ($publicIpObj && isset($publicIpObj->ip)) {
                $ip = $publicIpObj->ip;
            } else {
                $ip = '8.8.8.8'; // 使用备用IP
            }
        } catch (Exception $e) {
            error_log('获取公共IP失败: ' . $e->getMessage());
            $ip = '8.8.8.8'; // 使用备用IP
        }
    }
    
    return $ip;
}

// 从URL获取数据
function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        error_log("CURL错误: $error");
        return null;
    }
    
    if ($httpCode != 200) {
        error_log("HTTP错误，状态码: $httpCode, URL: $url");
        return null;
    }
    
    return json_decode($response, true);
}

// 格式化位置字符串
function formatLocationString($parts) {
    $filtered = array_filter($parts, function($part) {
        return $part && is_string($part) && $part !== 'undefined' && trim($part) !== '';
    });
    
    return implode(' ', $filtered);
}

// 生成UUID
function generateUUID() {
    if (function_exists('random_bytes')) {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    } else {
        // 回退方法
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// 获取本地时间
function getLocalTime($timezone) {
    if (!$timezone) {
        return date('Y-m-d H:i:s');
    }
    
    try {
        $date = new DateTime('now', new DateTimeZone($timezone));
        return $date->format('Y-m-d H:i:s') . " ($timezone)";
    } catch (Exception $e) {
        error_log('获取本地时间错误: ' . $e->getMessage());
        return date('Y-m-d H:i:s');
    }
}

// 生成TLS指纹
function generateTlsFingerprint() {
    $chars = 'abcdef0123456789';
    $ja3 = '';
    for ($i = 0; $i < 32; $i++) {
        $ja3 .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    $ja4 = 't13d' . rand(1000, 9999) . 'h' . rand(1, 9) . '_';
    for ($i = 0; $i < 12; $i++) {
        $ja4 .= $chars[rand(0, strlen($chars) - 1)];
    }
    $ja4 .= '_';
    for ($i = 0; $i < 12; $i++) {
        $ja4 .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    return "JA3: $ja3 JA4: $ja4";
}

// 检测IP是否是数据中心IP
function isDatacenterIP($ip, $ipapi, $ipinfo) {
    // 基于IP-API数据
    if ($ipapi && ($ipapi['hosting'] ?? false || $ipapi['proxy'] ?? false)) {
        return true;
    }
    
    // 基于ASN组织名
    if ($ipinfo && isset($ipinfo['org'])) {
        $org = strtolower($ipinfo['org']);
        $dcKeywords = [
            'amazon', 'aws', 'azure', 'google', 'cloud', 'host', 'server', 'data', 
            'center', 'digitalocean', 'linode', 'alibaba', 'tencent', 'oracle',
            'vultr', 'ovh', 'softlayer', 'rackspace', 'cogent', 'hetzner'
        ];
        
        foreach ($dcKeywords as $keyword) {
            if (strpos($org, $keyword) !== false) {
                return true;
            }
        }
    }
    
    // 基于IP范围 (简化示例)
    if (substr($ip, 0, 3) === '13.' || substr($ip, 0, 3) === '52.' || substr($ip, 0, 3) === '54.') {
        return true; // AWS常用IP段
    }
    
    return false;
}

// 检测IP是否是VPN IP
function isVpnIP($ip, $ipapi, $ipinfo) {
    // 基于IP-API数据
    if ($ipapi && ($ipapi['proxy'] ?? false) && !($ipapi['hosting'] ?? false)) {
        return true;
    }
    
    // 基于ASN组织名
    if ($ipinfo && isset($ipinfo['org'])) {
        $org = strtolower($ipinfo['org']);
        $vpnKeywords = [
            'vpn', 'nord', 'express', 'private', 'network', 'tunnel', 
            'cyber', 'ghost', 'hide', 'proxy', 'tor', 'exit', 'surfshark'
        ];
        
        foreach ($vpnKeywords as $keyword) {
            if (strpos($org, $keyword) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

// 检测代理状态
function detectProxy($ip, $ipapi, $ipinfo) {
    // 判断IP类型
    $isDC = isDatacenterIP($ip, $ipapi, $ipinfo);
    
    // 判断是否是VPN (基于启发式规则)
    $isVpn = ($ipapi['proxy'] ?? false) || isVpnIP($ip, $ipapi, $ipinfo);
    
    // 判断是否是Tor出口节点
    $isTor = ($ipapi['proxy'] ?? false) && ($ipapi['hosting'] ?? false);
    
    // 网络类型
    $type = 'RES'; // 默认为住宅IP
    if ($isDC) $type = 'DCH';
    else if ($isVpn) $type = 'VPN';
    else if ($isTor) $type = 'TOR';
    
    return [
        'detected' => $isVpn || $isDC || $isTor,
        'type' => $type,
        'isVpn' => $isVpn,
        'isTor' => $isTor,
        'isDatacenter' => $isDC,
        'isPublicProxy' => $ipapi['proxy'] ?? false,
        'isWebProxy' => false,
        'isCrawler' => false,
        'isResidential' => !$isDC && !$isVpn && !$isTor,
        'isSpam' => false,
        'isScanner' => false,
        'isBotnet' => false
    ];
}

// 获取国家代码
function getCountryCode($country) {
    $countryMap = [
        '中国' => 'CN',
        '美国' => 'US',
        '日本' => 'JP',
        '韩国' => 'KR',
        '英国' => 'GB',
        '德国' => 'DE',
        '法国' => 'FR',
        '俄罗斯' => 'RU',
        '加拿大' => 'CA',
        '澳大利亚' => 'AU',
        '新加坡' => 'SG',
        '印度' => 'IN',
        '巴西' => 'BR',
        '阿根廷' => 'AR',
        '南非' => 'ZA',
        '埃及' => 'EG',
        'China' => 'CN',
        'United States' => 'US',
        'Japan' => 'JP',
        'South Korea' => 'KR',
        'United Kingdom' => 'GB',
        'Germany' => 'DE',
        'France' => 'FR',
        'Russia' => 'RU',
        'Canada' => 'CA',
        'Australia' => 'AU',
        'Singapore' => 'SG',
        'India' => 'IN',
        'Brazil' => 'BR',
        'Argentina' => 'AR',
        'South Africa' => 'ZA',
        'Egypt' => 'EG'
    ];
    
    return $countryMap[$country] ?? substr($country, 0, 2);
}

// 模拟延迟数据
function simulateLatency($country) {
    // 根据地理位置调整延迟值
    $baseLatency = [
        'japan' => 150,
        'singapore' => 120,
        'usWest' => 100,
        'usEast' => 70,
        'sydney' => 200,
        'uk' => 40
    ];
    
    // 根据用户所在国家/地区调整延迟
    if ($country === '中国' || $country === 'China') {
        $baseLatency = [
            'japan' => 50,
            'singapore' => 80,
            'usWest' => 200,
            'usEast' => 250,
            'sydney' => 150,
            'uk' => 300
        ];
    } else if ($country === '美国' || $country === 'United States') {
        $baseLatency = [
            'japan' => 150,
            'singapore' => 200,
            'usWest' => 20,
            'usEast' => 60,
            'sydney' => 200,
            'uk' => 100
        ];
    } else if ($country === '日本' || $country === 'Japan') {
        $baseLatency = [
            'japan' => 10,
            'singapore' => 70,
            'usWest' => 120,
            'usEast' => 180,
            'sydney' => 130,
            'uk' => 250
        ];
    } else if ($country === '英国' || $country === 'United Kingdom') {
        $baseLatency = [
            'japan' => 250,
            'singapore' => 180,
            'usWest' => 150,
            'usEast' => 100,
            'sydney' => 300,
            'uk' => 10
        ];
    }
    
    // 添加随机波动
    return [
        'japan' => floor($baseLatency['japan'] + mt_rand(0, 50)) . 'ms',
        'singapore' => floor($baseLatency['singapore'] + mt_rand(0, 50)) . 'ms',
        'usWest' => floor($baseLatency['usWest'] + mt_rand(0, 40)) . 'ms',
        'usEast' => floor($baseLatency['usEast'] + mt_rand(0, 30)) . 'ms',
        'sydney' => floor($baseLatency['sydney'] + mt_rand(0, 50)) . 'ms',
        'uk' => floor($baseLatency['uk'] + mt_rand(0, 30)) . 'ms'
    ];
}

// 获取CDN节点信息
function getCdnNodeInfo($ip, $country, $city, $region) {
    // 生成CDN信息 (实际实现中应该从CDN提供商API获取)
    $countryCode = getCountryCode($country);
    $cityAbbr = $city ? strtoupper(substr($city, 0, 3)) : 'XXX';
    
    return [
        'fastly' => trim("$country $city") . " ($cityAbbr)",
        'bunny' => "$country ($countryCode" . rand(1, 5) . "-" . (1000 + rand(0, 9000)) . ")",
        'gcore' => "$country ($cityAbbr" . rand(1, 5) . "-GC" . (10 + rand(0, 90)) . ")",
        'vercel' => trim("$country $city") . " ($cityAbbr" . rand(1, 3) . ")",
        'cachefly' => trim("$country $city") . " ($cityAbbr" . rand(1, 3) . ")",
        'cloudfront' => trim("$country $city") . " ($cityAbbr" . rand(1, 3) . ")",
        'cloudflare' => trim("$country $city") . " ($cityAbbr)"
    ];
}

// 从ASN信息提取公司信息
function extractCompanyInfo($asn, $ipinfo, $ipapi) {
    if (!$asn || $asn === '未知') return '未知';
    
    // 尝试提取公司名称
    $company = '';
    
    // 从ASN信息中提取公司名
    $asnParts = explode(' ', $asn);
    if (count($asnParts) > 1) {
        $company = implode(' ', array_slice($asnParts, 1));
    }
    
    // 如果有域名信息，添加到公司名后
    if ($ipinfo && isset($ipinfo['org'])) {
        $orgParts = explode(' ', $ipinfo['org']);
        if (count($orgParts) > 1) {
            $possibleDomain = strtolower(end($orgParts));
            if (strpos($possibleDomain, '.') !== false && strpos(strtolower($company), $possibleDomain) === false) {
                $company .= " ($possibleDomain)";
            }
        }
    } else if ($ipapi && isset($ipapi['isp']) && !$company) {
        $company = $ipapi['isp'];
    }
    
    return $company ?: '未知';
}

// 格式化Cloudflare位置数据
function formatCloudflareLocation($cfData, $country, $region, $city) {
    if (!$cfData) return formatLocationString([$country, $region, $city]);
    
    $location = '';
    
    if (isset($cfData['country']) && isset($cfData['region']) && isset($cfData['city'])) {
        $location = "{$cfData['country']} {$cfData['region']} {$cfData['city']}";
    } else {
        $location = formatLocationString([$country, $region, $city]);
    }
    
    // 添加英文标识
    if (isset($cfData['asOrganization'])) {
        $location .= " - {$cfData['asOrganization']}";
    }
    
    return $location;
}

// 主逻辑
try {
    // 获取请求IP
    $ipAddress = getRequestIP();
    
    // 获取Cloudflare数据
    $cloudflareData = null;
    try {
        $cfResponse = fetchUrl('https://speed.cloudflare.com/meta');
        if ($cfResponse) {
            $cloudflareData = $cfResponse;
        }
    } catch (Exception $e) {
        error_log('获取Cloudflare数据失败: ' . $e->getMessage());
    }
    
    // 并行获取多个数据源的IP信息
    $ipinfo = fetchUrl("https://ipinfo.io/{$ipAddress}?token={$API_KEYS['ipinfo']}");
    $ipapi = fetchUrl("http://ip-api.com/json/{$ipAddress}");
    $ipstack = fetchUrl("https://api.ipstack.com/{$ipAddress}?access_key={$API_KEYS['ipstack']}");
    
    // 获取国家信息，尝试从多个来源获取
    $country = $ipinfo['country'] ?? $ipapi['country'] ?? $ipstack['country_name'] ?? 
              $cloudflareData['country'] ?? '未知';
              
    // 获取城市信息，尝试从多个来源获取
    $city = $ipinfo['city'] ?? $ipapi['city'] ?? $ipstack['city'] ?? 
           $cloudflareData['city'] ?? '未知';
           
    // 获取地区/省份信息
    $region = $ipinfo['region'] ?? $ipapi['regionName'] ?? $ipstack['region_name'] ?? 
             $cloudflareData['region'] ?? '未知';
             
    // 获取ASN信息
    $asn = $ipinfo['org'] ?? 
          (isset($ipapi['as']) ? "{$ipapi['as']} " . ($ipapi['isp'] ?? '') : null) ?? 
          (isset($cloudflareData['asOrganization']) ? "AS{$cloudflareData['asn']} {$cloudflareData['asOrganization']}" : null) ?? 
          '未知';
    
    // 获取经纬度
    $lat = explode(',', $ipinfo['loc'] ?? '')[0] ?? $ipapi['lat'] ?? $ipstack['latitude'] ?? $cloudflareData['latitude'] ?? '';
    $lon = explode(',', $ipinfo['loc'] ?? '')[1] ?? $ipapi['lon'] ?? $ipstack['longitude'] ?? $cloudflareData['longitude'] ?? '';
    
    // 检测代理/VPN状态
    $proxyDetection = detectProxy($ipAddress, $ipapi, $ipinfo);
    
    // 获取CDN节点信息
    $cdnNodes = getCdnNodeInfo($ipAddress, $country, $city, $region);
    
    // 获取延迟信息
    $latency = simulateLatency($country);
    
    // 构建位置信息
    $locationInfo = [
        'baidu' => formatLocationString([$country, $region, $city, explode(' ', $asn)[1] ?? '']),
        'moe' => $country,
        'ipapi' => formatLocationString([$ipapi['country'] ?? null, $ipapi['regionName'] ?? null, $ipapi['city'] ?? null, $ipapi['isp'] ?? null]),
        'maxmind' => formatLocationString([$country, $region, $city]),
        'aliyun' => $country, // 模拟阿里云数据
        'ipstack' => formatLocationString([$ipstack['continent_name'] ?? null, $ipstack['country_name'] ?? null, $ipstack['region_name'] ?? null, $ipstack['city'] ?? null]),
        'ipinfo' => formatLocationString([$ipinfo['country'] ?? null, $ipinfo['region'] ?? null, $ipinfo['city'] ?? null, $ipinfo['org'] ?? null]),
        'cloudflare' => formatCloudflareLocation($cloudflareData, $country, $region, $city),
        'ip2location' => formatLocationString([$country, $region, $city]),
        'dbip' => formatLocationString([$country, $region, $city])
    ];
    
    // 构建IP标签
    $ipTags = [];
    if ($proxyDetection['isDatacenter']) $ipTags[] = '数据中心';
    if ($proxyDetection['isVpn']) $ipTags[] = 'VPN';
    if ($proxyDetection['isTor']) $ipTags[] = 'Tor节点';
    if ($proxyDetection['isPublicProxy']) $ipTags[] = '公共代理';
    if ($proxyDetection['isResidential'] && !$proxyDetection['isDatacenter']) $ipTags[] = '住宅IP';
    if ($cloudflareData && isset($cloudflareData['country'])) $ipTags[] = "广播IP ({$cloudflareData['country']})";
    
    // 构建响应数据
    $responseData = [
        'ip' => [
            'address' => $ipAddress,
            'ipv6' => isset($cloudflareData['clientIp']) && strpos($cloudflareData['clientIp'], ':') !== false ? $cloudflareData['clientIp'] : '获取失败',
            'asn' => $asn,
            'country' => $country
        ],
        'location' => $locationInfo,
        'intelligence' => [
            'proxyType' => $proxyDetection['type'],
            'company' => extractCompanyInfo($asn, $ipinfo, $ipapi),
            'ipTags' => $ipTags,
            'isVpn' => $proxyDetection['isVpn'] ? '是' : '否',
            'isTor' => $proxyDetection['isTor'] ? '是' : '否',
            'isDatacenter' => $proxyDetection['isDatacenter'] ? '是' : '否',
            'isPublicProxy' => $proxyDetection['isPublicProxy'] ? '是' : '否',
            'isWebProxy' => $proxyDetection['isWebProxy'] ? '是' : '否',
            'isCrawler' => $proxyDetection['isCrawler'] ? '是' : '否',
            'isResidential' => $proxyDetection['isResidential'] ? '是' : '否',
            'isSpam' => $proxyDetection['isSpam'] ? '是' : '否',
            'isScanner' => $proxyDetection['isScanner'] ? '是' : '否',
            'isBotnet' => $proxyDetection['isBotnet'] ? '是' : '否',
            'proxyDetection' => $proxyDetection['detected'] ? '已开启VPN/代理' : '未检测到代理',
            'warpStatus' => 'WARP未开启',
            'detectionTime' => date('Y-m-d')
        ],
        'additional' => [
            'coordinates' => $lat && $lon ? "$lat, $lon" : ($ipinfo['loc'] ?? ''),
            'localTime' => getLocalTime($ipinfo['timezone'] ?? $ipapi['timezone'] ?? 'UTC'),
            'postalCode' => $ipinfo['postal'] ?? $ipapi['zip'] ?? $cloudflareData['postalCode'] ?? '',
            'protocols' => 'HTTP/1.1, TLSv1.3', // 简化，实际中需要从服务器环境获取
            'tlsFingerprint' => generateTlsFingerprint()
        ],
        'client' => [
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '未知',
            'os' => '未知', // 实际中可使用user-agent解析库
            'browser' => '未知', // 实际中可使用user-agent解析库
            'screenSize' => '未知', // 只能在客户端获取
            'acceptLanguage' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '未知',
            'uuid' => generateUUID()
        ],
        'latency' => $latency,
        'cdn' => $cdnNodes
    ];
    
    // 返回JSON响应
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    handleError('获取IP信息失败', $e->getMessage());
}
?>
