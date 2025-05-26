<?php

namespace kobitworks\ktMngMsg;


/**
 * Class MessageFileLoader
 * 
 * .messagesフォルダ内のJSONメッセージファイルを再帰的に読み込み、
 * 指定したプロンプト文字列の先頭にメッセージを追加する機能を提供します。
 *
 * JSONメッセージファイル構造例:
 * [
 *   {
 *     "note": {...}          // メモ。無視する
 *   },
 *   {
 *     "message": "テキストメッセージ"
 *   },
 *   {
 *     "message_json_file": "別のメッセージファイル.json" // 再帰的に読み込み
 *   },
 *   {
 *     "message_text_file": "単純テキストファイル.txt"   // テキストファイルの内容読み込み
 *   },
 *   {
 *     "file": "file_example.php"  // コードブロックとして読み込み。ファイル名は絶対パスに変換。
 *   }
 * ]
 *
 * ループ再帰防止のため、処理済みファイル一覧を保持し再度読み込まないようにする。
 * 
 * 指定ファイルが存在しない場合は、所定のテンプレートで新規作成する機能を追加。
 */
class MngMessage
{
    /**
     * コンストラクタ
     * @param string $aieDir AIEコードルートディレクトリのパス
     */
    public function __construct(string $aieDir='')
    {
        $this->aieDir = rtrim($aieDir, DIRECTORY_SEPARATOR);
    }

    /**
     * 指定されたメッセージファイルを起点として、
     * JSON構造に従い再帰的にメッセージを読み込み、与えられたプロンプトの先頭に連結する
     * ファイルが存在しなければテンプレートを自動生成。
     * 
     * @param string $msgFile メッセージファイル名（.messagesフォルダ内）
     * @return string メッセージを先頭に追加した文字列
     * @throws \Exception ファイル読み込み失敗時など
     */
    public function prependMessageFileContent(string $msgFile,string $prompt = '' ): string
    {
        if (empty($msgFile)) {
            return $prompt; // 空文字は何もしない
        }


        // 拡張子判定追加
        $ext = strtolower(pathinfo($msgFile, PATHINFO_EXTENSION));
        if ($ext === 'txt') {
            // テキストファイルとして読み込み、内容をそのまま返す（プロンプトの先頭に追加）
            // $msgFile が絶対パスか判定。絶対パスならそのまま使い、相対パスなら $this->aieDir 配下として解決
            if ($this->isAbsolutePath($msgFile)) {
                $filePath = $msgFile;
            } else {
                $filePath = $this->aieDir . DIRECTORY_SEPARATOR . $msgFile;
            }

            if (!file_exists($filePath)) {
                //throw new \Exception("テキストファイルが存在しません: {$filePath}");
                return '';
            }

            $textContent = @file_get_contents($filePath);
            if ($textContent === false) {
                //throw new \Exception("テキストファイルの読み込みに失敗しました: {$filePath}");
                return '';
            }

            return $textContent . "\n" . $prompt;
        }

        // $msgFileが絶対パスか判定。絶対パスならそのまま使い、相対パスなら $this->aieDir 配下として解決
        if ($this->isAbsolutePath($msgFile)) {
            $filePath = $msgFile;
            $messagesDir = dirname($filePath);
        } else {
            // 相対パスの場合は $aieDir 配下で解決
            $filePath = $this->aieDir . DIRECTORY_SEPARATOR . $msgFile;
            $messagesDir = dirname($filePath);
        }

        if ($messagesDir === '') {
            $messagesDir = '.';
        }

        // メッセージフォルダが存在しなければ作成
        if (!is_dir($messagesDir)) {
            if (!mkdir($messagesDir, 0777, true)) {
                throw new \Exception("メッセージフォルダの作成に失敗しました: {$messagesDir}");
            }
        }

        // 指定ファイルが存在しなければテンプレートを書き込む
        if (!file_exists($filePath)) {
            $this->createTemplateFile($filePath);
        }

        // 再帰時のループ回避
        if (isset($this->processedFiles[$filePath])) {
            // すでに処理済みなら空文字を返す（無限ループ回避）
            return $prompt;
        }
        $this->processedFiles[$filePath] = true;

        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception("メッセージファイルの読み込みに失敗しました: {$filePath}");
        }

        $jsonData = json_decode($content, true);
        if ($jsonData === null) {
            throw new \Exception("JSON形式の解析に失敗しました: {$filePath}");
        }

        $accumulatedMsg = '';

        foreach ($jsonData as $entry) {
            if (!is_array($entry)) {
                // 形式的に配列でないものはスキップ
                continue;
            }

            // noteキーはメモなので無視
            if (isset($entry['note'])) {
                continue;
            }

            // message: 文字列メッセージを取得
            if (isset($entry['message']) && is_string($entry['message'])) {
                $accumulatedMsg .= $entry['message'] . "\n";
                continue;
            }

            // message_json_file: 再帰読み込み（相対パスは.messagesディレクトリ内のファイル扱い）
            if (isset($entry['message_json_file']) && is_string($entry['message_json_file'])) {
                $nestedFile = trim($entry['message_json_file']);
                if ($nestedFile === '') {
                    continue;
                }
                // $nestedFile は $messagesDir を起点とした相対パス or 絶対パスとして解釈したい
                $nestedFilePath = $this->resolvePathRelativeToDir($nestedFile, $messagesDir);
                // 再帰呼び出し：ここではフルパスで渡すことでresolve処理を二重に避ける
                $nestedMsg = $this->prependMessageFileContent($nestedFilePath);
                if ($nestedMsg !== '') {
                    $accumulatedMsg .= $nestedMsg . "\n";
                }
                continue;
            }

            // message_text_file: .messages内のテキストファイルを読み込みそのままメッセージ文字列に追加
            if (isset($entry['message_text_file']) && is_string($entry['message_text_file'])) {
                $textFileName = trim($entry['message_text_file']);
                if ($textFileName === '') {
                    // 空文字なら処理スキップ
                    continue;
                }
                $textFilePath = $this->resolvePathRelativeToDir($textFileName, $messagesDir);
                if (!file_exists($textFilePath)) {
                    throw new \Exception("テキストメッセージファイルが存在しません: {$textFilePath}");
                }
                $textContent = @file_get_contents($textFilePath);
                if ($textContent === false) {
                    throw new \Exception("テキストメッセージファイルの読み込みに失敗しました: {$textFilePath}");
                }
                $accumulatedMsg .= $textContent . "\n";
                continue;
            }

            // file: ファイルの絶対パスでコードブロック形式にして追加
            // ここでは、$aieDir配下の相対パスとして扱い、フルパスに変換
            if (isset($entry['file']) && is_string($entry['file'])) {
                $fileRelativePath = trim($entry['file']);
                if ($fileRelativePath === '') {
                    // 空文字なら処理スキップ
                    continue;
                }
                $fileFullPath = $this->resolvePathRelativeToDir($fileRelativePath, $messagesDir);
                if (!file_exists($fileFullPath) || !is_file($fileFullPath)) {
                    //throw new \Exception("コードブロックとして読み込むファイルが存在しないかファイルではありません: {$fileFullPath}");
                    continue;
                }
                $codeContent = @file_get_contents($fileFullPath);
                if ($codeContent === false) {
                    //throw new \Exception("コードブロックファイルの読み込みに失敗しました: {$fileFullPath}");
                    continue;
                }

                $fileExtension = pathinfo($fileFullPath, PATHINFO_EXTENSION);
                // 【修正】コードブロックの言語指定に拡張子が空の場合は plaintext に fallback
                $language = $fileExtension !== '' ? $fileExtension : 'plaintext';
                $accumulatedMsg .= "```{$language}:{$fileFullPath}\n";
                $accumulatedMsg .= rtrim($codeContent, "\r\n") . "\n";
                $accumulatedMsg .= "```\n";
                continue;
            }
        }

        // 最終的にファイル内容を先頭に付加したプロンプトを返す
        return rtrim($accumulatedMsg, "\n") . "\n" . $prompt;
    }

    /**
     * 指定のパスを基準ディレクトリからの相対パスまたは絶対パスとして解決し、フルパスで返す
     * フルパスの場合はそのまま返す
     * 
     * @param string $path 指定パス（相対または絶対）
     * @param string $baseDir 基準ディレクトリの絶対パス
     * @return string フルパス
     */
    private function resolvePathRelativeToDir(string $path, string $baseDir): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function createTemplateFile(string $filePath): void
    {
        $result = @file_put_contents($filePath, self::TEMPLATE_JSON);
        if ($result === false) {
            throw new \Exception("メッセージファイルのテンプレート生成に失敗しました: {$filePath}");
        }
    }

    /**
     * 指定されたファイルの相対パスをaieDirルートからのフルパスに変換する。
     * 既に絶対パスの場合はそのまま返す。
     *
     * @param string $relativePath ファイルの相対パスまたは絶対パス
     * @return string フルパス
     */
    private function resolveFullPath(string $relativePath): string
    {
        if ($this->isAbsolutePath($relativePath)) {
            return $relativePath;
        }

        // 相対パスの場合はaieDirからの相対パスとして解決
        return $this->aieDir . DIRECTORY_SEPARATOR . $relativePath;
    }

    /**
     * 絶対パス判定を行う
     * WindowsとUnix系を考慮
     *
     * @param string $path
     * @return bool
     */
    private function isAbsolutePath_v1(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // "C:\"などの形式や"\\server\share" UNCパス
            return preg_match('/^[a-zA-Z]:\\\\/', $path) === 1 || substr($path, 0, 2) === '\\\\';
        } else {
            // "/"始まりは絶対パス
            return substr($path, 0, 1) === '/';
        }
    }

    /**
     * パスが絶対パスかどうかを返す
     *
     * @param string $path 判定したいパス文字列
     * @return bool 絶対パスなら true、相対パスなら false
     */
    private function isAbsolutePath(string $path): bool
    {
        // Unix 系 (先頭が /)
        if (strlen($path) > 0 && ($path[0] === '/' || $path[0] === '\\')) {
            return true;
        }

        // Windows ドライブレター (C:\ や D:/)
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return true;
        }

        // UNC パス (\\server\share...)
        if (substr($path, 0, 2) === '\\\\') {
            return true;
        }

        return false;
    }

    /**
     * AIEコードのルートディレクトリ
     * @var string
     */
    private string $aieDir;

    /**
     * メッセージ再帰展開で処理済みファイルの追跡用配列
     * @var array<string, bool>
     */
    private array $processedFiles = [];

    /**
     * メッセージファイル新規作成用のテンプレート配列
     * JSONの整形済みテキストとして保持
     * 
     * @var string
     */
    private const TEMPLATE_JSON = <<<JSON
[
  {
    "note": "コメント（出力無視）"
  },
  {
    "note": "message:通常出力のテキスト"
  },
  {
    "note": "message_json_file:このファイルを起点に相対パス指定/再帰的に読み込み"
  },
  {
    "note": "message_text_file:このファイルを起点に相対パス指定/テキストとして読み込み"
  },
  {
    "note": "file:ファイル全体を読み込み。マークダウンのコードブロックとして読み込み"
  },
  {
    "message": ""
  },
  {
    "message_json_file": ""
  },
  {
    "message_text_file": ""
  },
  {
    "file": ""
  }
]
JSON;

}