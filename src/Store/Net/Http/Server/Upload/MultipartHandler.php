<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */


namespace Psc\Store\Net\Http\Server\Upload;

use Closure;
use Psc\Store\Net\Http\Server\Exception\FormatException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Http上传解析器
 */
class MultipartHandler
{
    private const int STATUS_WAIT = 0;
    private const int STATUS_TRAN = 1;
    private const int STATUS_DONE = 2;
    /**
     * @var Closure
     */
    public Closure $onFile;
    /**
     * @var array
     */
    private array $files = array();
    /**
     * @var mixed
     */
    private mixed $currentTransferFile;
    /**
     * @var int
     */
    private int $status = MultipartHandler::STATUS_WAIT;
    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * 上传文件构造
     * @param string $boundary
     */
    public function __construct(private readonly string $boundary)
    {
    }

    /**
     * 上下文推入
     * @param string $context
     * @return void
     * @throws FormatException
     */
    public function push(string $context): void
    {
        $this->buffer .= $context;
        while (!empty($this->buffer)) {
            if ($this->status === MultipartHandler::STATUS_WAIT) {
                if (!$this->parseFileInfo()) {
                    break;
                }
            }

            if ($this->status === MultipartHandler::STATUS_TRAN) {
                if (!$this->processTransmitting()) {
                    break;
                }
            }

            if ($this->status === MultipartHandler::STATUS_DONE) {
                break;
            }

        }
    }

    /**
     * 解析文件信息
     * @return bool
     * @throws FormatException
     */
    private function parseFileInfo(): bool
    {
        $headerEndPosition = strpos($this->buffer, "\r\n\r\n");
        if ($headerEndPosition === false) {
            return false;
        }

        $header       = substr($this->buffer, 0, $headerEndPosition);
        $this->buffer = substr($this->buffer, $headerEndPosition + 4);

        $headerLines = explode("\r\n", $header);

        $meta1 = array_shift($headerLines);
        $meta2 = array_shift($headerLines);
        $meta3 = array_shift($headerLines);

        if ($meta1 !== '--' . $this->boundary) {
            throw new FormatException('Boundary is invalid');
        }

        if (!preg_match('/^Content-Disposition: form-data; name="([^"]+)"; filename="([^"]+)"$/i', $meta2, $matches2)) {
            throw new FormatException('File information is incomplete');
        }

        if (!preg_match('/^Content-Type: (.+)$/i', $meta3, $matches3)) {
            throw new FormatException('File information is incomplete');
        }

        $fileInfo                = array();
        $fileInfo['name']        = $matches2[1];
        $fileInfo['fileName']    = $matches2[2];
        $fileInfo['contentType'] = $matches3[1];
        $fileInfo['path']        = $this->createNewFile();
        $this->files[]           = $fileInfo;
        $this->status            = MultipartHandler::STATUS_TRAN;
        return true;
    }

    /**
     * 创建新文件
     * @return string
     */
    private function createNewFile(): string
    {
        $path                      = sys_get_temp_dir() . '/' . uniqid();
        $this->currentTransferFile = fopen($path, 'wb+');
        return $path;
    }

    /**
     * 处理传输中
     * @return bool
     */
    private function processTransmitting(): bool
    {
        $mode1 = "\r\n--{$this->boundary}\r\n";
        $mode2 = "\r\n--{$this->boundary}--\r\n";

        $mode1Length = strlen($mode1);
        $mode2Length = strlen($mode2);

        $boundaryPosition = strpos($this->buffer, $mode1);
        $modeLength       = $mode1Length;

        if ($boundaryPosition === false) {
            $boundaryPosition = strpos($this->buffer, $mode2);
            $modeLength       = $mode2Length;
        }

        if ($boundaryPosition !== false) {
            $this->buffer = substr($this->buffer, $boundaryPosition + $modeLength);

            $content = substr($this->buffer, 0, $boundaryPosition);
            fwrite($this->currentTransferFile, $content);
            fclose($this->currentTransferFile);
            $this->status = MultipartHandler::STATUS_WAIT;

            if (isset($this->onFile)) {
                $fileInfo = array_pop($this->files);
                call_user_func($this->onFile,
                    new UploadedFile(
                        $fileInfo['path'],
                        $fileInfo['fileName'],
                        $fileInfo['contentType']
                    ),
                    $fileInfo['name']
                );
            }
        } else {
            fwrite($this->currentTransferFile, $this->buffer);
            $this->buffer = '';
        }
        return true;
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->status === MultipartHandler::STATUS_TRAN) {
            fclose($this->currentTransferFile);
        }
    }

    /**
     * @return void
     */
    public function done(): void
    {
        $this->status = MultipartHandler::STATUS_DONE;
    }
}
