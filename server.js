// server.js - Express服务器
const express = require('express');
const axios = require('axios');
const cors = require('cors');
const path = require('path');
const geoip = require('geoip-lite');
const { v4: uuidv4 } = require('uuid');
const useragent = require('express-useragent');

const app = express();
const PORT = process.env.PORT || 3000;

// 中间件
app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));
app.use(useragent.express());

// API密钥配置 (实际使用时请替换为真实的API密钥并妥善保管)
const API_KEYS = {
    ipinfo: process.env.IPINFO_API_KEY || 'your_ipinfo_key',
    ipapi: process.env.IPAPI_KEY || 'your_ipapi_key',
    ipstack: process.env.IPSTACK_KEY || 'your_ipstack_key',
    maxmind: process.env.MAXMIND_KEY || 'your_maxmind_key'
};

// 获取IP信息的路由
app.get('/api/ip-info', async (req, res) => {
    try {
        // 获取要查询的IP地址，如果没有提供，则使用请求者的IP
        let ipAddress = req.query.ip || req.headers['x-forwarded-for'] || req.ip;
        
        // 如果IP是IPv6格式的IPv4（如::ffff:127.0.0.1），则提取IPv4部分
        if (ipAddress.includes('::ffff:')) {
            ipAddress = ipAddress.split(':').pop();
        }
        
        // 如果是本地IP，则使用公共IP查询服务获取真实IP
        if (ipAddress === '127.0.0.1' || ipAddress === 'localhost' || ipAddress === '::1') {
            try {
                const publicIpResponse = await axios.get('https://api.ipify.org?format=json');
                ipAddress = publicIpResponse.data.ip;
            } catch (error) {
                console.error('获取公共IP失败:', error);
                ipAddress = '8.8.8.8'; // 使用备用IP
            }
        }
        
        // 获取Cloudflare数据
        let cloudflareData = null;
        try {
            const cfResponse = await axios.get('https://speed.cloudflare.com/meta');
            if (cfResponse.status === 200) {
                cloudflareData = cfResponse.data;
            }
        } catch (error) {
            console.error('获取Cloudflare数据失败:', error);
        }
        
        // 并行获取多个数据源的IP信息
        const [
            ipinfoData,
            ipapiData,
            ipstackData,
            moeData,
            dbipData,
            ip2locationData
        ] = await Promise.allSettled([
            // IPinfo API
            axios.get(`https://ipinfo.io/${ipAddress}?token=${API_KEYS.ipinfo}`)
                .then(res => res.data)
                .catch(() => null),
            
            // IP-API
            axios.get(`http://ip-api.com/json/${ipAddress}`)
                .then(res => res.data)
                .catch(() => null),
            
            // IPstack
            axios.get(`https://api.ipstack.com/${ipAddress}?access_key=${API_KEYS.ipstack}`)
                .then(res => res.data)
                .catch(() => null),
            
            // Moe.tools API (模拟，实际中替换为真实API)
            simulateMoeData(ipAddress),
            
            // DB-IP (模拟，实际中替换为真实API)
            simulateDbipData(ipAddress),
            
            // IP2Location (模拟，实际中替换为真实API)
            simulateIp2LocationData(ipAddress)
        ]);
        
        // MaxMind (通过GeoIP-lite库本地查询)
        const maxmind = geoip.lookup(ipAddress);
        
        // 提取每个API的结果
        const ipinfo = ipinfoData.status === 'fulfilled' ? ipinfoData.value : null;
        const ipapi = ipapiData.status === 'fulfilled' ? ipapiData.value : null;
        const ipstack = ipstackData.status === 'fulfilled' ? ipstackData.value : null;
        const moe = moeData.status === 'fulfilled' ? moeData.value : null;
        const dbip = dbipData.status === 'fulfilled' ? dbipData.value : null;
        const ip2location = ip2locationData.status === 'fulfilled' ? ip2locationData.value : null;
        
        // 获取国家信息，尝试从多个来源获取
        const country = ipinfo?.country || ipapi?.country || ipstack?.country_name || 
                      cloudflareData?.country || maxmind?.country || '未知';
                      
        // 获取城市信息，尝试从多个来源获取
        const city = ipinfo?.city || ipapi?.city || ipstack?.city || 
                   cloudflareData?.city || maxmind?.city || '未知';
                   
        // 获取地区/省份信息
        const region = ipinfo?.region || ipapi?.regionName || ipstack?.region_name || 
                     cloudflareData?.region || maxmind?.region || '未知';
                     
        // 获取ASN信息
        const asn = ipinfo?.org || 
                  (ipapi?.as ? `${ipapi.as} ${ipapi.isp || ''}` : null) || 
                  (cloudflareData?.asOrganization ? `AS${cloudflareData.asn} ${cloudflareData.asOrganization}` : null) || 
                  '未知';
        
        // 获取经纬度
        const lat = ipinfo?.loc?.split(',')[0] || ipapi?.lat || ipstack?.latitude || cloudflareData?.latitude || '';
        const lon = ipinfo?.loc?.split(',')[1] || ipapi?.lon || ipstack?.longitude || cloudflareData?.longitude || '';
        
        // 检测代理/VPN状态
        const proxyDetection = await detectProxy(ipAddress, ipapi, ipinfo);
        
        // 获取CDN节点信息
        const cdnNodes = await getCdnNodeInfo(ipAddress, country, city, region);
        
        // 获取延迟信息（实际中需要通过ping或其他方式获取）
        const latency = simulateLatency(country);
        
        // 构建位置信息
        const locationInfo = {
            baidu: formatLocationString([country, region, city, asn?.split(' ')[1] || '']),
            moe: moe?.country || country,
            ipapi: formatLocationString([ipapi?.country, ipapi?.regionName, ipapi?.city, ipapi?.isp]),
            maxmind: formatLocationString([maxmind?.country, maxmind?.region, maxmind?.city]),
            aliyun: formatAliyunLocation(country), // 模拟阿里云数据
            ipstack: formatLocationString([ipstack?.continent_name, ipstack?.country_name, ipstack?.region_name, ipstack?.city]),
            ipinfo: formatLocationString([ipinfo?.country, ipinfo?.region, ipinfo?.city, ipinfo?.org]),
            cloudflare: formatCloudflareLocation(cloudflareData, country, region, city),
            ip2location: ip2location?.location || formatLocationString([country, region, city]),
            dbip: dbip?.location || formatLocationString([country, region, city])
        };
        
        // 构建IP标签
        const ipTags = [];
        if (proxyDetection.isDatacenter) ipTags.push('数据中心');
        if (proxyDetection.isVpn) ipTags.push('VPN');
        if (proxyDetection.isTor) ipTags.push('Tor节点');
        if (proxyDetection.isPublicProxy) ipTags.push('公共代理');
        if (proxyDetection.isResidential && !proxyDetection.isDatacenter) ipTags.push('住宅IP');
        if (cloudflareData && cloudflareData.country) ipTags.push(`广播IP (${cloudflareData.country})`);
        
        // 构建响应数据
        const responseData = {
            ip: {
                address: ipAddress,
                ipv6: cloudflareData?.clientIp?.includes(':') ? cloudflareData.clientIp : '获取失败',
                asn: asn,
                country: country
            },
            location: locationInfo,
            intelligence: {
                proxyType: proxyDetection.type,
                company: extractCompanyInfo(asn, ipinfo, ipapi),
                ipTags: ipTags,
                isVpn: proxyDetection.isVpn ? '是' : '否',
                isTor: proxyDetection.isTor ? '是' : '否',
                isDatacenter: proxyDetection.isDatacenter ? '是' : '否',
                isPublicProxy: proxyDetection.isPublicProxy ? '是' : '否',
                isWebProxy: proxyDetection.isWebProxy ? '是' : '否',
                isCrawler: proxyDetection.isCrawler ? '是' : '否',
                isResidential: proxyDetection.isResidential ? '是' : '否',
                isSpam: proxyDetection.isSpam ? '是' : '否',
                isScanner: proxyDetection.isScanner ? '是' : '否',
                isBotnet: proxyDetection.isBotnet ? '是' : '否',
                proxyDetection: proxyDetection.detected ? '已开启VPN/代理' : '未检测到代理',
                warpStatus: 'WARP未开启',
                detectionTime: new Date().toISOString().split('T')[0]
            },
            additional: {
                coordinates: lat && lon ? `${lat}, ${lon}` : (ipinfo?.loc || ''),
                localTime: getLocalTime(ipinfo?.timezone || ipapi?.timezone || 'UTC'),
                postalCode: ipinfo?.postal || ipapi?.zip || cloudflareData?.postalCode || '',
                protocols: detectProtocols(req),
                tlsFingerprint: generateTlsFingerprint()
            },
            client: {
                userAgent: req.useragent.source,
                os: req.useragent.os,
                browser: `${req.useragent.browser} ${req.useragent.version}`,
                screenSize: '未知', // 只能在客户端获取
                acceptLanguage: req.headers['accept-language'] || '未知',
                uuid: uuidv4()
            },
            latency: latency,
            cdn: cdnNodes
        };
        
        res.json(responseData);
    } catch (error) {
        console.error('获取IP信息失败:', error);
        res.status(500).json({ error: '获取IP信息失败', details: error.message });
    }
});

// 辅助函数: 格式化位置字符串
function formatLocationString(parts) {
    return parts.filter(part => part && typeof part === 'string' && part !== 'undefined' && part.trim() !== '').join(' ');
}

// 辅助函数: 从ASN信息提取公司信息
function extractCompanyInfo(asn, ipinfo, ipapi) {
    if (!asn || asn === '未知') return '未知';
    
    // 尝试提取公司名称
    let company = '';
    
    // 从ASN信息中提取公司名
    const asnParts = asn.split(' ');
    if (asnParts.length > 1) {
        company = asnParts.slice(1).join(' ');
    }
    
    // 如果有域名信息，添加到公司名后
    if (ipinfo && ipinfo.org) {
        const orgParts = ipinfo.org.split(' ');
        if (orgParts.length > 1) {
            const possibleDomain = orgParts[orgParts.length - 1].toLowerCase();
            if (possibleDomain.includes('.') && !company.toLowerCase().includes(possibleDomain)) {
                company += ` (${possibleDomain})`;
            }
        }
    } else if (ipapi && ipapi.isp && !company) {
        company = ipapi.isp;
    }
    
    return company || '未知';
}

// 辅助函数: 获取指定时区的当前时间
function getLocalTime(timezone) {
    const options = {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    };
    
    try {
        const formatter = new Intl.DateTimeFormat('zh-CN', options);
        const formattedDate = formatter.format(new Date())
            .replace(/\//g, '-')
            .replace(',', '');
        return `${formattedDate} (${timezone})`;
    } catch (error) {
        console.error('格式化日期错误:', error);
        return new Date().toISOString().replace('T', ' ').substr(0, 19);
    }
}

// 辅助函数: 检测IP是否是代理/VPN
async function detectProxy(ip, ipapi, ipinfo) {
    try {
        // 判断IP类型
        const isDC = isDatacenterIP(ip, ipapi, ipinfo);
        
        // 判断是否是VPN (基于启发式规则)
        const isVpn = ipapi?.proxy || isVpnIP(ip, ipapi, ipinfo);
        
        // 判断是否是Tor出口节点
        const isTor = ipapi?.proxy && ipapi?.hosting;
        
        // 网络类型
        let type = 'RES'; // 默认为住宅IP
        if (isDC) type = 'DCH';
        else if (isVpn) type = 'VPN';
        else if (isTor) type = 'TOR';
        
        return {
            detected: isVpn || isDC || isTor,
            type: type,
            isVpn: isVpn,
            isTor: isTor,
            isDatacenter: isDC,
            isPublicProxy: ipapi?.proxy || false,
            isWebProxy: false,
            isCrawler: false,
            isResidential: !isDC && !isVpn && !isTor,
            isSpam: false,
            isScanner: false,
            isBotnet: false
        };
    } catch (error) {
        console.error('检测代理失败:', error);
        return {
            detected: false,
            type: 'Unknown',
            isVpn: false,
            isTor: false,
            isDatacenter: false,
            isPublicProxy: false,
            isWebProxy: false,
            isCrawler: false,
            isResidential: true,
            isSpam: false,
            isScanner: false,
            isBotnet: false
        };
    }
}

// 辅助函数: 判断是否是数据中心IP
function isDatacenterIP(ip, ipapi, ipinfo) {
    // 基于IP-API数据
    if (ipapi && (ipapi.hosting || ipapi.proxy)) {
        return true;
    }
    
    // 基于ASN组织名
    if (ipinfo && ipinfo.org) {
        const org = ipinfo.org.toLowerCase();
        const dcKeywords = [
            'amazon', 'aws', 'azure', 'google', 'cloud', 'host', 'server', 'data', 
            'center', 'digitalocean', 'linode', 'alibaba', 'tencent', 'oracle',
            'vultr', 'ovh', 'softlayer', 'rackspace', 'cogent', 'hetzner'
        ];
        
        for (const keyword of dcKeywords) {
            if (org.includes(keyword)) {
                return true;
            }
        }
    }
    
    // 基于IP范围 (简化示例)
    if (ip.startsWith('13.') || ip.startsWith('52.') || ip.startsWith('54.')) {
        return true; // AWS常用IP段
    }
    
    return false;
}

// 辅助函数: 判断是否是VPN IP
function isVpnIP(ip, ipapi, ipinfo) {
    // 基于IP-API数据
    if (ipapi && ipapi.proxy && !ipapi.hosting) {
        return true;
    }
    
    // 基于ASN组织名
    if (ipinfo && ipinfo.org) {
        const org = ipinfo.org.toLowerCase();
        const vpnKeywords = [
            'vpn', 'nord', 'express', 'private', 'network', 'tunnel', 
            'cyber', 'ghost', 'hide', 'proxy', 'tor', 'exit', 'surfshark'
        ];
        
        for (const keyword of vpnKeywords) {
            if (org.includes(keyword)) {
                return true;
            }
        }
    }
    
    return false;
}

// 辅助函数: 获取CDN节点信息
async function getCdnNodeInfo(ip, country, city, region) {
    // 生成CDN信息 (实际实现中应该从CDN提供商API获取)
    const countryCode = getCountryCode(country);
    const cityAbbr = city ? city.substring(0, 3).toUpperCase() : 'XXX';
    
    return {
        fastly: `${country} ${city || ''}`.trim() + ` (${cityAbbr})`,
        bunny: `${country} (${countryCode}${Math.floor(1 + Math.random() * 5)}-${Math.floor(1000 + Math.random() * 9000)})`,
        gcore: `${country} (${cityAbbr}${Math.floor(1 + Math.random() * 5)}-GC${Math.floor(10 + Math.random() * 90)})`,
        vercel: `${country} ${city || ''}`.trim() + ` (${cityAbbr}${Math.floor(1 + Math.random() * 3)})`,
        cachefly: `${country} ${city || ''}`.trim() + ` (${cityAbbr}${Math.floor(1 + Math.random() * 3)})`,
        cloudfront: `${country} ${city || ''}`.trim() + ` (${cityAbbr}${Math.floor(1 + Math.random() * 3)})`,
        cloudflare: `${country} ${city || ''}`.trim() + ` (${cityAbbr})`
    };
}

// 辅助函数: 获取国家代码
function getCountryCode(country) {
    const countryMap = {
        '中国': 'CN',
        '美国': 'US',
        '日本': 'JP',
        '韩国': 'KR',
        '英国': 'UK',
        '德国': 'DE',
        '法国': 'FR',
        '俄罗斯': 'RU',
        '加拿大': 'CA',
        '澳大利亚': 'AU',
        '新加坡': 'SG',
        '印度': 'IN',
        '巴西': 'BR',
        '阿根廷': 'AR',
        '南非': 'ZA',
        '埃及': 'EG',
        'China': 'CN',
        'United States': 'US',
        'Japan': 'JP',
        'South Korea': 'KR',
        'United Kingdom': 'GB',
        'Germany': 'DE',
        'France': 'FR',
        'Russia': 'RU',
        'Canada': 'CA',
        'Australia': 'AU',
        'Singapore': 'SG',
        'India': 'IN',
        'Brazil': 'BR',
        'Argentina': 'AR',
        'South Africa': 'ZA',
        'Egypt': 'EG'
    };
    
    return countryMap[country] || country.substring(0, 2).toUpperCase();
}

// 辅助函数: 模拟延迟数据
function simulateLatency(country) {
    // 根据地理位置调整延迟值
    let baseLatency = {
        japan: 150,
        singapore: 120,
        usWest: 100,
        usEast: 70,
        sydney: 200,
        uk: 40
    };
    
    // 根据用户所在国家/地区调整延迟
    if (country === '中国' || country === 'China') {
        baseLatency = {
            japan: 50,
            singapore: 80,
            usWest: 200,
            usEast: 250,
            sydney: 150,
            uk: 300
        };
    } else if (country === '美国' || country === 'United States') {
        baseLatency = {
            japan: 150,
            singapore: 200,
            usWest: 20,
            usEast: 60,
            sydney: 200,
            uk: 100
        };
    } else if (country === '日本' || country === 'Japan') {
        baseLatency = {
            japan: 10,
            singapore: 70,
            usWest: 120,
            usEast: 180,
            sydney: 130,
            uk: 250
        };
    } else if (country === '英国' || country === 'United Kingdom') {
        baseLatency = {
            japan: 250,
            singapore: 180,
            usWest: 150,
            usEast: 100,
            sydney: 300,
            uk: 10
        };
    }
    
    // 添加随机波动
    return {
        japan: `${Math.floor(baseLatency.japan + Math.random() * 50)}ms`,
        singapore: `${Math.floor(baseLatency.singapore + Math.random() * 50)}ms`,
        usWest: `${Math.floor(baseLatency.usWest + Math.random() * 40)}ms`,
        usEast: `${Math.floor(baseLatency.usEast + Math.random() * 30)}ms`,
        sydney: `${Math.floor(baseLatency.sydney + Math.random() * 50)}ms`,
        uk: `${Math.floor(baseLatency.uk + Math.random() * 30)}ms`
    };
}

// 辅助函数: 生成TLS指纹
function generateTlsFingerprint() {
    const chars = 'abcdef0123456789';
    let ja3 = '';
    for (let i = 0; i < 32; i++) {
        ja3 += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    
    let ja4 = `t13d${Math.floor(1000 + Math.random() * 9000)}h${Math.floor(1 + Math.random() * 9)}_`;
    for (let i = 0; i < 12; i++) {
        ja4 += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    ja4 += '_';
    for (let i = 0; i < 12; i++) {
        ja4 += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    
    return `JA3: ${ja3} JA4: ${ja4}`;
}

// 辅助函数: 检测HTTP协议版本
function detectProtocols(req) {
    let protocols = [];
    
    // 检测HTTP版本
    if (req.httpVersionMajor === 2) {
        protocols.push('HTTP/2');
    } else if (req.httpVersionMajor === 1) {
        protocols.push(`HTTP/${req.httpVersionMajor}.${req.httpVersionMinor}`);
    }
    
    // 检测TLS版本 (实际需要更底层的访问，这里模拟)
    if (req.secure || req.headers['x-forwarded-proto'] === 'https') {
        // 大多数现代浏览器都使用TLS 1.3
        protocols.push('TLSv1.3');
    }
    
    return protocols.join(', ');
}

// 辅助函数: 模拟Moe.tools API数据
async function simulateMoeData(ip) {
    // 在实际实现中，应该调用真实的API
    // 这里返回模拟数据
    return { country: '未知' };
}

// 辅助函数: 模拟DB-IP数据
async function simulateDbipData(ip) {
    // 在实际实现中，应该调用真实的API
    return { location: '未知' };
}

// 辅助函数: 模拟IP2Location数据
async function simulateIp2LocationData(ip) {
    // 在实际实现中，应该调用真实的API
    return { location: '未知' };
}

// 辅助函数: 格式化阿里云位置数据
function formatAliyunLocation(country) {
    // 在实际实现中，应该调用阿里云的API
    // 这里返回基于国家的模拟数据
    return country;
}

// 辅助函数: 格式化Cloudflare位置数据
function formatCloudflareLocation(cfData, country, region, city) {
    if (!cfData) return formatLocationString([country, region, city]);
    
    let location = '';
    
    if (cfData.country && cfData.region && cfData.city) {
        location = `${cfData.country} ${cfData.region} ${cfData.city}`;
    } else {
        location = formatLocationString([country, region, city]);
    }
    
    // 添加英文标识
    if (cfData.asOrganization) {
        location += ` - ${cfData.asOrganization}`;
    }
    
    return location;
}

// 健康检查路由
app.get('/health', (req, res) => {
    res.status(200).json({ status: 'ok', timestamp: new Date().toISOString() });
});

// 处理根路径
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// 启用错误处理中间件
app.use((err, req, res, next) => {
    console.error('服务器错误:', err);
    res.status(500).json({ error: '服务器内部错误', details: err.message });
});

// 启动服务器
app.listen(PORT, () => {
    console.log(`服务器运行在 http://localhost:${PORT}`);
});
