#!/usr/bin/env bash
#
# php-ai 发版：一条命令跑完「提交 → 推两个远端 → 打标签 → 校验 composer 真能拉到」
#
# 为什么必须串成一条链：本项目是 Composer 包，**只推代码不打标签 = 这次改动对用户
# 完全不存在**（Composer 只认标签，用户 composer update 拉不到，也不报错，只会静默
# 停在旧版本）。而本项目有两个远端，少推一个同样会静默失效：
#
#   origin   likun.work 内部仓库（MCI 服务器直接 clone 用，不走 composer）
#   github   GitHub（composer.json 的 source/dist 都指向它，Packagist 也只看 GitHub）
#
# 推完还必须自检两件事：标签真的在两个远端上、composer 真能装到这一版。
#
# 用法：
#   bin/release.sh v2.5.4 -m "fix(agent): 修 xxx"     提交当前改动并发版
#   bin/release.sh v2.5.4 --no-commit                 给当前 HEAD 补打标签发版
#   bin/release.sh v2.5.4 -m "..." --no-composer      跳过「真的装一遍」的校验（离线/赶时间）
#   bin/release.sh v2.5.4 --verify-only               不提交不推送，只校验这一版的发布状态
#
# 版本号规则见 .claude/CLAUDE.md：修 bug 升末位、加功能升中位、破坏性变更升首位。

set -euo pipefail

PKG="likun-mci/php-ai"
BRANCH="main"
REMOTES=(origin github)
PACKAGIST_API="https://repo.packagist.org/p2/${PKG}.json"

TAG=""
MSG=""
DO_COMMIT=1
DO_COMPOSER=1
VERIFY_ONLY=0

die()  { echo "✗ $*" >&2; exit 1; }
info() { echo "· $*"; }
ok()   { echo "✓ $*"; }

usage() {
    # 只回显文件开头的注释块，不会带出代码
    awk 'NR >= 3 && /^#/ { sub(/^# ?/, ""); print; next } NR >= 3 { exit }' "${BASH_SOURCE[0]}"
    exit "${1:-0}"
}

# ---------- 参数 ----------
while [ $# -gt 0 ]; do
    case "$1" in
        -m)           MSG="${2:-}"; [ -n "$MSG" ] || die "-m 后面要跟提交说明"; shift 2 ;;
        --no-commit)  DO_COMMIT=0; shift ;;
        --no-composer) DO_COMPOSER=0; shift ;;
        --verify-only) VERIFY_ONLY=1; shift ;;
        -h|--help)    usage 0 ;;
        -*)           die "未知选项：$1（-h 看用法）" ;;
        *)            [ -z "$TAG" ] || die "只接受一个版本号，多余的参数：$1"; TAG="$1"; shift ;;
    esac
done

[ -n "$TAG" ] || usage 1
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "版本号要形如 v2.5.4，收到：${TAG}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# ---------- 前置检查 ----------
[ -d .git ] || [ -f .git ] || die "这里不是 git 工作副本：${ROOT}"

for r in "${REMOTES[@]}"; do
    git remote get-url "$r" >/dev/null 2>&1 || die "缺少远端 ${r}（git remote add $r <url>）"
done

if [ "$VERIFY_ONLY" -eq 0 ]; then
    CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
    [ "$CUR_BRANCH" = "$BRANCH" ] || die "当前分支是 ${CUR_BRANCH}，发版要在 ${BRANCH} 上"

    git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null && die "本地已有标签 ${TAG}（版本号只增不减，换一个更高的）"
    for r in "${REMOTES[@]}"; do
        if git ls-remote --tags "$r" "refs/tags/${TAG}" | grep -q "refs/tags/${TAG}$"; then
            die "远端 ${r} 上已有标签 ${TAG}；若那是误建的重复标签，先 git push ${r} :refs/tags/${TAG}"
        fi
    done

    LATEST="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' | sort -V | tail -1 || true)"
    if [ -n "$LATEST" ] && [ "$(printf '%s\n%s\n' "$LATEST" "$TAG" | sort -V | tail -1)" != "$TAG" ]; then
        die "新版本号 ${TAG} 不高于已有的 ${LATEST}"
    fi

    if [ "$DO_COMMIT" -eq 1 ]; then
        [ -n "$MSG" ] || die "要么用 -m \"提交说明\" 顺带提交，要么用 --no-commit 只给当前 HEAD 打标签"
        [ -n "$(git status --porcelain)" ] || die "没有可提交的改动（别在旧 commit 上打出一个空标签；只想发版用 --no-commit）"
    else
        [ -n "$(git status --porcelain)" ] && die "工作区有未提交改动，--no-commit 会把它们漏在标签外"
        info "工作区干净，给当前 HEAD 打标签"
    fi
fi

# ---------- 校验函数 ----------
tag_on_remote() {  # $1=远端 $2=标签
    git ls-remote --tags "$1" "refs/tags/$2" | grep -q "refs/tags/$2$"
}

remote_tag_commit() {  # $1=远端 $2=标签 → 打印它指向的 commit
    # 注释标签的 refs/tags/X 指向 tag 对象，^{} 才是它剥出来的 commit；
    # 轻量标签没有 ^{} 行，退回 refs/tags/X 本身
    local sha
    sha="$(git ls-remote "$1" "refs/tags/$2^{}" | awk 'NR==1{print $1}')"
    [ -n "$sha" ] || sha="$(git ls-remote "$1" "refs/tags/$2" | awk 'NR==1{print $1}')"
    echo "$sha"
}

check_local_tag() {
    local desc
    desc="$(git describe --exact-match --tags HEAD 2>/dev/null || true)"
    [ "$desc" = "$TAG" ] || die "HEAD 上的标签是「${desc:-无}」，不是 ${TAG}"

    local n
    n="$(git tag --points-at HEAD | wc -l | tr -d ' ')"
    [ "$n" = "1" ] || die "HEAD 上挂了 ${n} 个标签（应该只有 ${TAG}）：
$(git tag --points-at HEAD)"
    ok "本地：HEAD 上只有 ${TAG}"
}

check_remote_tags() {
    local head r sha
    head="$(git rev-parse HEAD)"
    for r in "${REMOTES[@]}"; do
        tag_on_remote "$r" "$TAG" || die "远端 ${r} 上没有标签 ${TAG}（composer 会看不到这次发版）"
        sha="$(remote_tag_commit "$r" "$TAG")"
        [ "$sha" = "$head" ] || die "远端 ${r} 的 ${TAG} 指向 ${sha:0:8}，本地 HEAD 是 ${head:0:8}"
        ok "远端 ${r}：${TAG} → ${sha:0:8}"
    done
}

check_packagist() {
    local head short i body
    head="$(git rev-parse HEAD)"
    short="$(git rev-parse --short HEAD)"

    for i in $(seq 1 9); do
        # 还没收录时 p2 接口就是 404，这属于正常中间态，别把 curl 的报错喷到屏幕上
        body="$(curl -fsS --max-time 20 "$PACKAGIST_API" 2>/dev/null || true)"
        if printf '%s' "$body" | grep -q "\"version\":\"${TAG}\""; then
            if printf '%s' "$body" | grep -q "$head"; then
                ok "Packagist：已收录 ${TAG}（${short}）"
            else
                ok "Packagist：已收录 ${TAG}"
                info "注意：Packagist 记录的 commit 不是本地 HEAD（${short}），确认没推错分支"
            fi
            return 0
        fi
        [ "$i" -eq 1 ] && info "等 Packagist 收录 ${TAG}（抓取是异步的，最多约 3 分钟）…"
        sleep 20
    done

    echo "⚠️  Packagist 三分钟内仍未收录 ${TAG}：" >&2
    echo "    到 https://packagist.org/packages/${PKG} 点 Update，" >&2
    echo "    或看 GitHub Actions 的 Update Packagist 那次运行是否失败。" >&2
    echo "    不收录取不到新版本，这次发版等于没发。" >&2
    return 1
}

check_composer_install() {
    command -v composer >/dev/null 2>&1 || { echo "⚠️  本机没有 composer，跳过实装校验" >&2; return 0; }

    local head tmp
    head="$(git rev-parse HEAD)"
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' RETURN

    cat > "$tmp/composer.json" <<JSON
{
    "name": "likun-mci/php-ai-release-check",
    "description": "发版校验用的临时项目，只在 release.sh 里存在几秒",
    "require": { "${PKG}": "${TAG}" }
}
JSON

    info "在临时目录里真的装一遍 ${PKG}:${TAG}（走 Packagist + GitHub zipball）…"
    if ! COMPOSER_CACHE_DIR="$tmp/cache" COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1 \
         composer update --no-progress --quiet --working-dir="$tmp"; then
        die "composer 装不上 ${TAG}（Packagist 收录了但 zipball 拉不下来？看上面的报错）"
    fi

    local ref
    ref="$(php -r '
        $l = json_decode(file_get_contents($argv[1]), true);
        foreach ($l["packages"] as $p) {
            if ($p["name"] === $argv[2]) { echo $p["source"]["reference"]; }
        }
    ' "$tmp/composer.lock" "$PKG")"
    [ -n "$ref" ] || die "composer.lock 里没有 ${PKG}"
    [ "$ref" = "$head" ] || die "composer 装到的 commit 是 ${ref:0:8}，本地 HEAD 是 ${head:0:8}"

    ok "composer：${PKG}:${TAG} 装到了 ${ref:0:8}（与本地 HEAD 一致）"
}

# ---------- 发版 ----------
if [ "$VERIFY_ONLY" -eq 0 ]; then
    if [ "$DO_COMMIT" -eq 1 ]; then
        info "提交：${MSG}"
        git add -A
        git commit -q -m "$MSG"
        ok "已提交 $(git rev-parse --short HEAD)"
    fi

    # 两个远端都要推代码：github 拿不到 commit，后面标签也指不到东西
    for r in "${REMOTES[@]}"; do
        info "推送分支 ${BRANCH} → ${r}"
        git push -q "$r" "$BRANCH" || die "推 ${r} 的 ${BRANCH} 失败"
    done

    # 标签用注释标签（-a），与既有版本一致；附一句人话，方便 git show 时知道这版干了什么
    git tag -a "$TAG" -m "${TAG}: ${MSG:-发布 ${TAG}}"
    for r in "${REMOTES[@]}"; do
        info "推送标签 ${TAG} → ${r}"
        git push -q "$r" "$TAG" || {
            echo "⚠️  ${TAG} 已建在本地但没能推给 ${r}。" >&2
            echo "    补推：git push ${r} ${TAG}" >&2
            echo "    确认这次commit不该发版时：git tag -d ${TAG}（别的远端已推的标签按 .claude/CLAUDE.md 处理）" >&2
            exit 1
        }
    done
else
    info "--verify-only：只校验，不提交不推送"
fi

echo
echo "── 校验 ──"
check_local_tag
check_remote_tags
check_packagist
[ "$DO_COMPOSER" -eq 1 ] && check_composer_install

echo
if [ "$VERIFY_ONLY" -eq 1 ]; then
    ok "${TAG} 的发布状态校验通过"
else
    ok "发版完成：${TAG}"
    echo "  运行实例生效：cd /home/wwwroot/likun.work && composer update ${PKG}"
    echo "  改了 PHP 类要让 worker 换新代码：php MCI.php --reload"
fi
