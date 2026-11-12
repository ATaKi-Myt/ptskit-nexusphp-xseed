# NexusPHP 辅种映射插件（nexusphp-xseed）

自动发现「本站的哪些种子，在别的装了本插件的站点上也有」，并在种子列表和详情页展示出来，方便发布者一次做种、多站辅种。

> 只做 **pieces_hash 精确匹配**（= `sha1(info.pieces)`，只跟文件内容和分片大小有关），不做跨种/近似匹配。

- 种子列表多一列「辅种」（数字 = 有同种的友站数量，点击进详情）
- 详情页基本信息下方多一行：每个友站一个图标 + 站名，点击直达对方种子页
- 后台「Other」分组下新增「辅种站点」：增删改友站，保存后按各自间隔自动同步
- 只对**登录用户**显示，游客看不到；不改动 NexusPHP 任何站点文件

---

## 一、匹配依据：pieces_hash，不是 info_hash

跨站识别同一个种子，用的是 `torrents.pieces_hash`（= `sha1(info.pieces)`）。

**为什么不能用 info_hash**：NexusPHP 发种时会往 info 字典里写本站指纹（`app/Repositories/UploadRepository.php`）：

```php
$dict['info']['private'] = 1;
$dict['info']['source']  = sprintf("[%s] %s", Setting::getBaseUrl(), Setting::getSiteName());
$infoHash = pack("H*", sha1(Bencode::encode($dict['info'])));
```

`source` 在 info 字典**内部**，而 info_hash 就是整个 info 字典的 SHA1 —— 所以同一套片源在两个站生成的种子，info_hash **必然不同**。

真实例子（同名同片源的 2 集短剧）：PTS 侧 `source = "[www.ptskit.org] 拾刻"`、DXJ 侧 `source = "[pt.daxiangjiao.org] DaXiangJiao"`，`pieces` 的 sha1 完全相同，但 info_hash 一个 `1402b4a8…`、一个 `d893b1ce…`。

`pieces` 是各分片 SHA1 的拼接，只跟**文件内容**和**分片大小**有关，与 name / source / private / 文件路径全都无关，因此跨站稳定。主程序自己也在用它找同源种子（`POST /api/pieces-hash`）。

所以真正的硬前提只有两条：

| 必须一致 | 说明 |
|---|---|
| 文件清单 + 每个文件的大小 | 差一个附加文件（`.nfo` / `Sample` / `.DS_Store`）就不同 |
| 分片大小（piece length） | 切点不同，pieces 就完全不同 |

`name`、`source`、`private`、顶层目录名都**不影响**匹配（仍建议统一，便于人工识别）。

**建议**：互通站点约定同一套建种规则，例如按总大小分档固定 piece length（<1G→1M，1-4G→2M，>4G→4M），顶层目录名用统一格式。

两个注意点：

- `pieces_hash` 由主程序维护；老种子若为空会被跳过，跑 `php artisan torrent:load_pieces_hash` 补齐（线上每天 01:00 自动跑一次）。
- 不支持 hybrid（v1+v2）种子，建种请选 **BT v1 only**。

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
cd /www/wwwroot/nexus && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.2" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

- `COMPOSER_ALLOW_SUPERUSER=1` + `--no-interaction`：免掉 root 下的 `Continue as root/super user [yes]?` 交互，否则 `&&` 链路会卡在提问上。
- `composer require` 只把代码放进 `vendor/`，**不会建表**（Composer 只执行 root package 的 scripts，依赖包自己声明的 `post-package-install` 会被忽略），所以后面必须跟 `php artisan plugin install`。
- 中途任何一步失败，后面的都不会执行；重跑整条命令是安全的（迁移幂等）。
- 全新站点可以把 3.1 那条 `composer config repositories.xseed ...` 用 `&&` 接在最前面，一样是一条命令。
- 万一 `composer require` 没把旧版升上去（锁文件没动），改用 `composer update ptskit/nexusphp-xseed --no-interaction` 再跟后半段。

### 3.3 更新

升级用**同一条命令**，版本约束保持不变即可——已装过的话 `composer require` 会把这个包装到约束内的最新版，`plugin install` 只执行新增的迁移（跑过的自动跳过）：

```bash
cd /www/wwwroot/nexus && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.2" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

想钉到确定版本就写死，例如 `composer require "ptskit/nexusphp-xseed:0.2.0"`。

> **从 0.1.x 升到 0.2.x 必须手动改约束**：`^0.1` 覆盖不到 `0.2.0`，要照上面这条显式写 `^0.2`。
> 0.2.0 把匹配键从 `info_hash` 换成 `pieces_hash`，**互通的两边都要升**；混版时对端会返回 `peer expects "pieces", please upgrade ...`，本地不会静默变成 0 命中。
> 升级会自动重建 `plugin_xseed_peers`（纯缓存，不丢真实数据），重跑一次同步即恢复。若提示依赖冲突，在 `composer require` 后加 `-W`（`--with-all-dependencies`）。

> ⚠️ 只清 `config` / `route` / `view` 三个缓存，**不要**跑 `php artisan optimize:clear` 或 `cache:clear`：NexusPHP 把「后台令牌权限」存在缓存里，清空了会让后台取消的勾选回填，等于越权。

### 3.4 验证安装

```bash
cd /www/wwwroot/nexus && php artisan list | grep xseed
```

应出现 `xseed:sync`。

```bash
cd /www/wwwroot/nexus && php artisan tinker --execute="echo json_encode(\Illuminate\Support\Facades\Schema::getColumnListing('plugin_xseed_peers'));"
```

应输出 `["id","torrent_id","site_id","pieces_hash","peer_torrent_id","checked_at"]`。

再确认调度项已经生效：

```bash
cd /www/wwwroot/nexus && php artisan schedule:list | grep xseed
```

应看到 `xseed:sync ...每一分钟`。看不到的话执行 `php artisan route:clear && php artisan config:clear`（**不要**用 `optimize:clear`）。

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

### 保存即开始，之后自动跑

后台点「添加友站」保存后**不需要再点任何按钮**：新记录的 `last_sync_at = 0`，第一次同步在下一分钟内自动开始；表单里的「同步间隔（分钟）」默认 30，之后按这个节奏自动同步。

实现是挂进 Laravel 调度（和 `routes/console.php` 里 `horizon:snapshot` 同一套）：

```php
$schedule->command('xseed:sync')->everyMinute()->withoutOverlapping();
```

调度器每分钟叫一次命令，命令内部再按每条友站的 `interval_minutes` 判断是否到期，没到期直接跳过（只多一次很轻的本地 SQL）。所以站点多也不怕，各自按各自的间隔跑。

**前提**：站点本身要有 `schedule:run` 的 cron —— NexusPHP 默认就有（`horizon:snapshot` 靠它）。确认一下：

```bash
crontab -l | grep schedule:run
```

没有就补一条：

```
* * * * * cd /www/wwwroot/nexus && php artisan schedule:run >> /dev/null 2>&1
```

### 手动立刻同步

```bash
cd /www/wwwroot/nexus && php artisan xseed:sync --site=1     # 指定友站，忽略间隔
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

`--all` 表示忽略间隔、只跑一次，等价于把每个站的间隔都临时当成立刻到期：

```bash
cd /www/wwwroot/nexus && php artisan xseed:sync --all
```

同步是**增量**的：已经问过的种子不会再问，所以第二次跑通常几条到几十条，秒级完成。首次全量每轮最多处理 30 批（3 万个种子），剩下的一分钟后接着跑。

### 定时同步（不用手工配 crontab）

不需要为这个插件单独加 cron —— 它挂在 Laravel 调度里，只要站点已经在跑 `schedule:run` 就行（见上）。

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
| 命中率极低 / 全是 0 | ① 两边版本不一致（对端 `<0.2`）；② 分片大小或文件清单不同，见第一节；③ 本地 `pieces_hash` 为空 |
| `peer expects "pieces", please upgrade ...` | 对端还是 0.1.x，把它也升到 `^0.2` |
| 保存后一直没同步，`last_sync_result` 为空 | 站点没跑 `schedule:run`（上面那条 cron），或 `php artisan schedule:list` 里看不到 `xseed:sync` |
| 大量种子被跳过（`total` 很小） | `torrents.pieces_hash` 为空 → `php artisan torrent:load_pieces_hash` 补，线上每天 01:00 也会自动补 |

---

## 六、数据结构

两张表，**全部是可再生缓存**，清空后重跑一次 `xseed:sync` 即可恢复。以后若改为中心服务器汇总，只需换数据来源，表结构和前端不用动。

```sql
plugin_xseed_sites    -- 友站名单：name / base_url / secret / icon / enabled / interval_minutes / last_sync_*
plugin_xseed_peers    -- 交集缓存：torrent_id / site_id / pieces_hash(char 40) / peer_torrent_id / checked_at
```

`peer_torrent_id = 0` 表示「这个 pieces_hash 在这个站查过了、对方没有」，用于避免每轮重复询问。

`pieces_hash` 直接取自 `torrents.pieces_hash`（主程序维护），插件自己不解析 .torrent 文件，所以同步只是几次普通 SQL + 一次 HTTP。

---

## 七、接口说明

两个端点都不挂 `web` 中间件组（无 CSRF、无 session）：

### `POST /plugin-xseed/api/lookup`

对端来问「这批 pieces_hash 在你站是哪些种子」。

```
Header: X-Xseed-Site: https://caller.example.com
Header: X-Xseed-Ts:   1763000000
Header: X-Xseed-Sign: HMAC_SHA256(ts + rawBody, shared_secret)
Body:   {"pieces":["40位hex", ...]}      // 最多 1000 个

Resp:   {"ret":0,"msg":"OK","data":{"<pieces_hash>":"<torrent_id>"}}   // 只返回命中的
```

时间窗 ±300 秒，防重放。只返回 `pieces_hash → torrent_id`，不返回种子名、不上报任何用户信息。

> 0.1.x 的老对端发的还是 `{"hashes":[...]}`，这里会返回 HTTP 400 + `ret:-1`，msg 里提示对方升级，避免它误以为是「没有同源种子」。

### `GET /plugin-xseed/api/counts`

本站列表页用，无鉴权（只暴露「某种子有几个友站有」，与详情页展示的信息等价）。

```
GET /plugin-xseed/api/counts?ids=1,2,3      // 最多 200 个
Resp: {"ret":0,"msg":"OK","data":{"1":2}}
```

---

## 八、已知限制

- 列表页那一列是异步 JS 补的，会有一次短暂闪动，且不参与排序
- 首次全量同步是分批的：每轮最多 30 批（3 万个种子），10 万种子大约 4 轮跑完（每轮间隔 1 分钟）
- 没有自动失效清理：友站删种后本地记录仍在（计划中）
- 只支持 BT v1 种子，不支持 v2 / hybrid
- 匹配要求分片大小一致；一边 1M、一边 2M 就匹配不上
- 不做跨种匹配（文件不完全相同则匹配不到）

---

## 九、卸载

```bash
cd /www/wwwroot/nexus && php artisan plugin uninstall ptskit/nexusphp-xseed
```

```bash
cd /www/wwwroot/nexus && composer remove ptskit/nexusphp-xseed
```

第一条回滚迁移（删表），第二条移除代码。不用管 cron：同步是注册在插件里的调度项，代码移除后自然就不跑了。

---

## 十、多语言

内置 `zh_CN`（简体）、`zh_TW`（繁体）、`en`（英文）三套文案，落在 `resources/lang/{zh_CN,zh_TW,en}/xseed.php`，三份文件键完全一致。

- **跟随站点语言**：站点切到简体 / 繁体 / 英文，后台（侧栏名、表头、状态、按钮）和前台（详情页那一行的标签、列表页列名）一起跟着变。
- **默认简体**：站点语言不在这三种之内、或取不到语言设置时，一律回落到 `zh_CN`，不会出现中英混排。
- **状态列**显示「启用 / 停用」，不是 1 / 0。

想加别的语言：在 `resources/lang/` 下新建目录（如 `ja`），把 `zh_CN/xseed.php` 复制过去翻译即可，没翻到的键自动回落简体。

> 目录名要用 NexusPHP 的 **locale** 名（`zh_CN` / `zh_TW` / `en`），不是前台的语言文件夹名（`chs` / `cht` / `en`）。插件内部已做 `chs → zh_CN`、`cht → zh_TW` 的映射，不用自己处理。

---

## License

MIT
