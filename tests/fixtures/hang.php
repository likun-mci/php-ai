<?php
/**
 * 一个不肯退出的假 CLI：写下自己的 PID 后一直挂着。
 * 用来验证「一次性模式超时后，claude 进程是否真的被收掉」。
 */
if (isset($argv[1]) && $argv[1] !== '') {
    file_put_contents($argv[1], (string) getmypid());
}
while (true) {
    usleep(200000);
}
