# nexusphp-xseed

NexusPHP 辅种插件。自动发现「本站的种子在哪些友站也有」，在种子列表和详情页直接标出来，省掉手工比对。

- 种子列表多一列「辅种」，数字 = 有同源的友站数量，点击看详情
- 种子详情页多一行：每个友站一个图标 + 站名，点击直达对方种子页
- 后台「Other」分组下多一个「辅种站点」，增删改友站，保存后自动同步，无需手工点按钮
- 只对登录用户显示，游客看不到
- **不改动站点任何文件**，卸载即干净移除

多语言：简体中文 / 繁体中文 / 英文，跟随站点语言，默认简体。

---

## 匹配原理

按 **pieces_hash** 匹配，即 `sha1(info.pieces)`，只跟文件内容和分片大小有关。

不用 info_hash：NexusPHP 发种时会把站名和域名写进 info 字典（`source` 字段），而 info_hash 是整个 info 字典的摘要，所以**同一套片源在两个站生成的种子，info_hash 必然不同**。pieces 在 info 之外的那部分不参与，因此跨站稳定。

硬前提只有两条：

| 必须一致 | 说明 |
|---|---|
| 文件清单 + 每个文件大小 | 差一个附加文件（`.nfo` / `Sample` / `.DS_Store`）就对不上 |
| 分片大小（piece length） | 切点不同，pieces 完全不同 |

`name`、`source`、`private`、顶层目录名都不影响匹配（仍建议统一，便于人工识别）。

建议互通站点约定同一套建种规则，例如按总大小分档固定分片：<1G→1M、1–4G→2M、>4G→4M。

两个注意点：

- 老种子若 `pieces_hash` 为空会被跳过，跑一次 `php artisan torrent:load_pieces_hash` 补齐（默认每天 01:00 自动补）。
- 不支持 hybrid（v1+v2）种子，建种请选 **BT v1 only**。

---

## 环境要求

- NexusPHP **>= 1.9.0**
- PHP 8.1+ / MySQL 8 或 MariaDB 10.5+
- 服务器能主动访问友站的 HTTPS 地址
- 有 `composer` 和 `php` 可执行

---

## 安装

NexusPHP 根目录以 `/www/wwwroot/nexus` 为例，按自己站点改。

**首次安装**（注册仓库 + 装包 + 建表，一条命令）：

```bash
cd /www/wwwroot/nexus && composer config repositories.xseed git https://github.com/ATaKi-Myt/ptskit-nexusphp-xseed.git && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.2" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

**更新**（已装过，同一条命令，版本约束不变）：

```bash
cd /www/wwwroot/nexus && COMPOSER_ALLOW_SUPERUSER=1 composer require "ptskit/nexusphp-xseed:^0.2" --no-interaction && php artisan plugin install ptskit/nexusphp-xseed && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

说明：

- `composer require` 只把代码放进 `vendor/`，不建表，所以后面必须跟 `php artisan plugin install`。重跑整条命令是安全的，迁移幂等。
- 想钉死版本可写 `ptskit/nexusphp-xseed:0.2.2`。依赖冲突时在 `composer require` 后加 `-W`。
- 从 `0.1.x` 升级必须把约束改成 `^0.2`（`^0.1` 覆盖不到 `0.2.x`），且**互通的两边都要升**。
- ⚠️ 只清 `config` / `route` / `view` 三个缓存。**不要**跑 `optimize:clear` 或 `cache:clear`，会把后台令牌权限设置清成默认值。

验证：

```bash
cd /www/wwwroot/nexus && php artisan list | grep xseed && php artisan schedule:list | grep xseed
```

分别应看到 `xseed:sync` 和每分钟的调度项。再打开后台 `/nexusphp` → 侧栏 **Other** → 有「辅种站点」即成功。都没有就执行 `php artisan package:discover`。

---

## 配置互通

需要**两边都装本插件**。假设 A、B 两站，约定一个共享密钥 `S`（32 位以上随机串，`openssl rand -hex 32` 生成）：

| 在哪配 | 站点名称 | 站点地址 | 通讯密钥 |
|---|---|---|---|
| A 站后台 | `B 站` | `https://b.example.com` | `S` |
| B 站后台 | `A 站` | `https://a.example.com` | `S` |

要点：

1. A 里填 B 的域名，B 里填 A 的域名，**都不要填自己的**。
2. 两边 secret 必须同一个值。
3. 地址末尾不带斜杠，`https` / `http` 要和对方实际访问方式一致。

只在一侧配置也能用，但只有配了的那侧能看到结果；要双向显示就两侧都配。

### 保存即开始，之后自动跑

保存友站后不需要再点任何按钮：第一次同步在下一分钟内自动开始，之后按表单里的「同步间隔」（默认 30 分钟）自动执行。站点多也不怕，各站按各自间隔跑。

插件挂在 Laravel 调度里，只要站点本身在跑 `schedule:run` 就行（NexusPHP 默认就有）。确认一下：

```bash
crontab -l | grep schedule:run
```

没有就补一条：

```
* * * * * cd /www/wwwroot/nexus && php artisan schedule:run >> /dev/null 2>&1
```

### 手动同步

```bash
cd /www/wwwroot/nexus && php artisan xseed:sync --site=1     # 指定友站，忽略间隔
cd /www/wwwroot/nexus && php artisan xseed:sync              # 所有启用的友站，按间隔判断
cd /www/wwwroot/nexus && php artisan xseed:sync --all        # 所有友站，忽略间隔
```

同步是增量的：已经在缓存表里问过的种子（不管命中还是未命中）不会重复问，第二次跑通常秒级完成。首次全量分批进行，一轮最多 30 批 × 1000 条 = 3 万条种子；没跑完的**要等下一个同步间隔**才继续（不是一分钟后），想立刻接着跑就再执行一次 `xseed:sync --site=1`。

后台列表里「最后同步时间 / 命中 / 未命中」就是最近一次同步的结果；上一轮失败时命中列显示 `-` 并标红，鼠标悬停可看原始错误。

---

## 故障排查

| 现象 | 原因 / 处理 |
|---|---|
| `lookup failed, http 404` | 对方没装插件或路由未加载 → 对方执行 `php artisan route:clear` |
| `lookup failed, http 500` | 对方站点报错，看对方 `storage/logs/laravel.log` |
| `unauthorized` | 对方的友站列表里没有你的域名，或那条被禁用；也可能是**两边写的地址不一致**（`http` 与 `https`、带不带 `www`），核对站点设置里的 BASEURL |
| `bad signature` | 两边 secret 不一致 |
| `expired` | 两台服务器时间相差超过 5 分钟，先校准时间 |
| 后台没有「辅种站点」菜单 | `php artisan package:discover`，再 `config:clear` |
| `php artisan list` 没有 `xseed:sync` | 同上 |
| 保存后一直没同步 | 站点没跑 `schedule:run`，或 `schedule:list` 里看不到 `xseed:sync` |
| 列表页没多出「辅种」列 | 该页种子都没匹配到；或浏览器缓存了旧 JS，强刷 |
| 详情页不显示 | 只对登录用户显示，且该种子确实没有同源 |
| 命中率极低 / 全是 0 | ① 两边版本不一致（对端 <0.2）② 分片大小或文件清单不同 ③ 本地 `pieces_hash` 为空 |
| `peer expects "pieces", please upgrade ...` | 对端还是 0.1.x，升到 `^0.2` |
| 被跳过的种子很多 | `torrents.pieces_hash` 为空 → `php artisan torrent:load_pieces_hash` |

---

## 已知限制

- 列表页那一列是异步补的，有短暂闪动，不参与排序
- 友站删种后本地记录不会自动清理
- 只支持 BT v1 种子，不支持 v2 / hybrid
- 要求分片大小一致，一边 1M 一边 2M 就匹配不上
- 不做跨种匹配，文件不完全相同则匹配不到

---

## 卸载

```bash
cd /www/wwwroot/nexus && php artisan plugin uninstall ptskit/nexusphp-xseed && composer remove ptskit/nexusphp-xseed
```

第一条回滚迁移删表，第二条移除代码。不用管 cron，代码没了调度项自然就不跑。

---

## 多语言

内置 `zh_CN` / `zh_TW` / `en` 三套文案，跟随站点语言切换，默认简体。

要加别的语言：在 `resources/lang/` 下新建 locale 目录（如 `ja`），复制 `zh_CN/xseed.php` 翻译即可，没翻到的键自动回落简体。

> 目录名用 NexusPHP 的 locale 名（`zh_CN` / `zh_TW` / `en`），不是前台的语言文件夹名（`chs` / `cht`）。插件内部已做映射，不用自己处理。

---

## License

MIT
