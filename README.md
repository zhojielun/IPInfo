# IPInfo
# PHP版IP信息查询工具

这是一个使用PHP实现的IP信息查询工具后端，配合HTML/CSS/JavaScript前端使用，可查询用户当前IP或指定IP的详细信息。

## 部署说明

### 前提条件

- PHP 7.2 或更高版本
- 启用了cURL扩展
- Web服务器(Apache/Nginx/IIS等)

### 安装步骤

1. **设置Web服务器**

   将前端HTML文件和PHP后端文件上传到Web服务器。

   **Apache目录结构示例**:
   ```
   /var/www/html/
   ├── index.html        # 前端页面
   ├── api/
   │   └── ip-info.php   # PHP后端API
   ```

2. **设置API密钥**

   编辑`ip-info.php`文件，替换API密钥:

   ```php
   $API_KEYS = [
       'ipinfo' => '你的ipinfo密钥',
       'ipapi' => '你的ipapi密钥',
       'ipstack' => '你的ipstack密钥',
       'maxmind' => '你的maxmind密钥'
   ];
   ```

   或者使用环境变量设置(推荐):

   **Apache (.htaccess)**:
   ```
   SetEnv IPINFO_API_KEY 你的ipinfo密钥
   SetEnv IPAPI_KEY 你的ipapi密钥
   SetEnv IPSTACK_KEY 你的ipstack密钥
   SetEnv MAXMIND_KEY 你的maxmind密钥
   ```

   **Nginx (nginx.conf)**:
   ```
   location ~ \.php$ {
       fastcgi_param IPINFO_API_KEY 你的ipinfo密钥;
       fastcgi_param IPAPI_KEY 你的ipapi密钥;
       fastcgi_param IPSTACK_KEY 你的ipstack密钥;
       fastcgi_param MAXMIND_KEY 你的maxmind密钥;
       # 其他fastcgi配置...
   }
   ```

3. **修改前端API路径**

   编辑前端HTML文件中的JavaScript部分，确保API调用指向正确的PHP文件:

   ```javascript
   // 替换这行
   const apiUrl = `/api/ip-info?ip=${ip}`;
   
   // 使用正确的PHP文件路径
   const apiUrl = `/api/ip-info.php?ip=${ip}`;
   ```

4. **设置正确的权限**

   确保Web服务器有权限读取和执行PHP文件:

   ```bash
   chmod 644 ip-info.php
   ```

5. **测试安装**

   在浏览器中打开你的网站，测试IP信息
