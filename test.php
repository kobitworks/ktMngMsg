<?php
require 'vendor/autoload.php';

use kobitworks\ktMngMsg\MngMessage;

// AIEコードルートディレクトリを指定（この例ではカレントディレクトリに合わせる）
$aiedir = __DIR__;

// MngMessageのインスタンスを生成
$mng = new MngMessage($aiedir);

// メッセージを先頭に追加した結果を取得
echo $mng->prependMessageFileContent('.messages/sample.json');
