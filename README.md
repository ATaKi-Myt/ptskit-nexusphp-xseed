# NexusPHP 辅种映射插件（nexusphp-xseed）

自动发现「本站的哪些种子，在别的装了本插件的站点上也有」，并在种子列表和详情页展示出来，方便发布者一次做种、多站辅种。

> 只做 **exact info_hash 匹配**（同一个 BT v1 info_hash），不做按文件/piece 对齐的跨种匹配。

- 种子列表多一列「辅种」（数字 = 有同种的友站数量，点击进详情）
- 详情页基本信息下方多一行：每个友站一个图标 + 站名，点击直达对方种子页
- 后台「Other」分组下新增「辅种站点」：增删改友站、立即同步
- 只对**登录用户**显示，游客看不到；不改动 NexusPHP 任何站点文件

---

## 一、前提：两站的 info_hash 必须完全相同

跨站识别同一个种子，唯一依据是 `torrents.info_hash`（BT v1 的 SHA1，`binary(20)`）。以下任何一项不同，hash 就不同，匹配不上：

| 因素 | 说明 |
|---|---|
| piece length | 最常见的差异。两边必须一致 |
| 顶层目录名 / 文件名 | 即 info 字典里的 `name` |
| 附加文件 | `.nfo`、`Sample`、`Subs`、`.DS_Store`、`Thumbs.db` 混进去就变 |
| private 标志 | 设与不设，info 字典不同 |

**建议**：参与互通的站点先约定同一套建种规则，例如按总大小分档固定 piece length（<1G→1M，1-4G→2M，>4G→4M），顶层目录名用统一格式。否则功能能跑，但命中率会很低。

另外，qBittorrent 5.x 默认可能生成 **hybrid（v1+v2）** 种子，其 v2 hash 与本站的 v1 hash 无关。建种时请选择 **BT v1 only**。

---

## 二、环境要求

- NexusPHP **>= 1.9.0**（`VERSION_NUMBER`）
- 主程序已安装 `spatie/laravel-package-tools`、`filament/filament`（NexusPHP 自带）
- PHP 8.1+ / MySQL 8 或 MariaDB 10.5+
- 服务器能主动访问友站的 HTTPS 地址
- 服务器有 `composer`、`php` 可执行

---

## 三、安装

把下面命令里的路径按自己站点调整，NexusPHP 根目录以 `/www/wwwroot/nexus` 为例。

### 3.1 注册仓库

```bash
cd /www/wwwroot/nexus && composer config repositories.xseed git https://github.com/ATaKi-Myt/ptskit-nexusphp-xseed.git
```

### 3.2 安装包并建表（一条命令）

NexusPHP 插件要「装代码」+「建表 + 清缓存」两步，串起来一条命令跑完：

```bash
cd /www/wwwroot/nexus && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.1" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

- `COMPOSER_ALLOW_SUPERUSER=1` + `--no-interaction`：免掉 root 下的 `Continue as root/super user [yes]?` 交互，否则 `&&` 链路会卡在提问上。
- `composer require` 只把代码放进 `vendor/`，**不会建表**（Composer 只执行 root package 的 scripts，依赖包自己声明的 `post-package-install` 会被忽略），所以后面必须跟 `php artisan plugin install`。
- 中途任何一步失败，后面的都不会执行；重跑整条命令是安全的（迁移幂等）。
- 全新站点可以把 3.1 那条 `composer config repositories.xseed ...` 用 `&&` 接在最前面，一样是一条命令。
- 万一 `composer require` 没把旧版升上去（锁文件没动），改用 `composer update ptskit/nexusphp-xseed --no-interaction` 再跟后半段。

### 3.3 更新

升级用**同一条命令**，版本约束保持不变即可——已装过的话 `composer require` 会把这个包装到约束内的最新版，`plugin install` 只执行新增的迁移（跑过的自动跳过）：

```bash
cd /www/wwwroot/nexus && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.1" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

想钉到确定版本就写死，例如 `composer require "ptskit/nexusphp-xseed:0.1.2"`。若提示依赖冲突，在 `composer require` 后加 `-W`（`--with-all-dependencies`）。

> ⚠️ 只清 `config` / `route` / `view` 三个缓存，**不要**跑 `php artisan optimize:clear` 或 `cache:clear`：NexusPHP 把「后台令牌权限」存在缓存里，清空了会让后台取消的勾选回填，等于越权。

### 3.4 验证安装

```bash
cd /www/wwwroot/nexus && php artisan list | grep xseed
```

应出现 `xseed:sync`。

```bash
cd /www/wwwroot/nexus && php artisan tinker --execute="echo json_encode(\Illuminate\Support\Facades\Schema::getColumnListing('plugin_xseed_peers'));"
```

应输出 `["id","torrent_id","site_id","info_hash","peer_torrent_id","checked_at"]`。

进后台 `/nexusphp` → 侧栏 **Other** 分组 → 看到「辅种站点」即安装成功。看不到的话：

```bash
cd /www/wwwroot/nexus && php artisan package:discover
```

---

## 四、配置两站互通

需要**两边都安装本插件**。假设 A、B 两站，约定一个共享密钥 `S`（32 位以上随机串，可用 `openssl rand -hex 32` 生成）。

| 在哪配 | 站点名称 | 站点地址 | 通讯密钥 |
|---|---|---|---|
| A 站后台 | `B 站` | `https://b.example.com` | `S` |
| B 站后台 | `A 站` | `https://a.example.com` | `S` |

要点：

1. **A 里填的地址必须是 B 的域名，B 里填的必须是 A 的域名**（不要填自己的）。
2. **两边 secret 是同一个值**。
3. 地址末尾**不要带斜杠**，且与站点实际访问的 scheme + 域名完全一致（`https` / `http` 要对应）。

原理：A 请求 B 时带 `X-Xseed-Site: https://a.example.com`，B 用这个头在自己的友站列表里找到「A 站」那条记录，取其中的 secret 验签。

### 手动跑一次

```bash
cd /www/wwwroot/nexus && php artisan xseed:sync --site=1
```

成功输出：

```
同步 B 站 (https://b.example.com) ...
  完成：查询 1024 条，命中 37 条，共 2 批
```

不带 `--site` 就是同步所有启用的友站：

```bash
cd /www/wwwroot/nexus && php artisan xseed:sync
```

同步是**增量**的：已经问过的 hash 不会再问，所以第二次跑通常几条到几十条，秒级完成。

### 定时同步

```bash
crontab -e
```

加一行（每小时一次）：

```
0 * * * * cd /www/wwwroot/nexus && php artisan xseed:sync >/dev/null 2>&1
```

> 单向也可以：只在 A 站配置 B 站，A 就能看到 B 有同种；但 B 看不到 A。要让两边都显示，两边都要配。

---

## 五、故障排查

| 现象 | 原因 / 处理 |
|---|---|
| `lookup failed, http 404` | 对方没装插件，或对方路由未加载 → 对方执行 `php artisan route:clear` |
| `lookup failed, http 500` | 对方站点报错，去看对方 `storage/logs/laravel.log` |
| `unauthorized` | 对方的友站列表里没有你的域名，或那条记录被禁用 |
| `bad signature` | 两边 secret 不一致 |
| `expired` | 两台服务器时间相差超过 5 分钟，先校准时间（`ntpdate` / `chrony`） |
| 后台没有「辅种站点」菜单 | `php artisan package:discover`，再 `config:clear` |
| `php artisan list` 里没有 `xseed:sync` | 同上；命令是随 ServicesProvider 注册的 |
| 列表页没多出「辅种」列 | 该页 200 个种子都没匹配到；或浏览器缓存了旧 JS（强刷）；空结果时不显示该列 |
| 详情页不显示 | 只对登录用户显示；且该种子在 `plugin_xseed_peers` 里没有 `peer_torrent_id > 0` 的记录 |
| 命中率极低 | 两站建种参数不一致，见第一节 |

---

## 六、数据结构

两张表，**全部是可再生缓存**，清空后重跑一次 `xseed:sync` 即可恢复。以后若改为中心服务器汇总，只需换数据来源，表结构和前端不用动。

```sql
plugin_xseed_sites    -- 友站名单：name / base_url / secret / icon / enabled / last_sync_*
plugin_xseed_peers    -- 交集缓存：torrent_id / site_id / info_hash(binary 20) / peer_torrent_id
```

`peer_torrent_id = 0` 表示「这个 hash 在这个站查过了、对方没有」，用于避免每轮重复询问。

---

## 七、接口说明

两个端点都不挂 `web` 中间件组（无 CSRF、无 session）：

### `POST /plugin-xseed/api/lookup`

对端来问「这批 hash 在你站是哪些种子」。

```
Header: X-Xseed-Site: https://caller.example.com
Header: X-Xseed-Ts:   1763000000
Header: X-Xseed-Sign: HMAC_SHA256(ts + rawBody, shared_secret)
Body:   {"hashes":["40位hex", ...]}      // 最多 1000 个

Resp:   {"ret":0,"msg":"OK","data":{"<hash>":"<torrent_id>"}}   // 只返回命中的
```

时间窗 ±300 秒，防重放。只返回 `hash → torrent_id`，不返回种子名、不上报任何用户信息。

### `GET /plugin-xseed/api/counts`

本站列表页用，无鉴权（只暴露「某种子有几个友站有」，与详情页展示的信息等价）。

```
GET /plugin-xseed/api/counts?ids=1,2,3      // 最多 200 个
Resp: {"ret":0,"msg":"OK","data":{"1":2}}
```

---

## 八、已知限制

- 列表页那一列是异步 JS 补的，会有一次短暂闪动，且不参与排序
- 首次全量同步较慢（10 万种子约 100 批），建议先命令行跑完再挂定时任务
- 没有自动失效清理：友站删种后本地记录仍在（计划中）
- 只支持 BT v1 info_hash，不支持 v2 / hybrid 种子
- 不做 piece 级跨种匹配（文件不完全相同则匹配不到）

---

## 九、卸载

```bash
cd /www/wwwroot/nexus && php artisan plugin uninstall ptskit/nexusphp-xseed
```

```bash
cd /www/wwwroot/nexus && composer remove ptskit/nexusphp-xseed
```

第一条回滚迁移（删表），第二条移除代码。别忘了把 crontab 里那条也删掉。

---

## License

MIT
