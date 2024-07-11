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


namespace Psc\Store\Net\Http\Server;

use Psc\Core\Output;
use Psc\Std\Stream\Exception\RuntimeException;

/**
 * Http上传解析器
 */
class RequestUpload
{
    public const int STATUS_ILLEGAL = -1;    # 非法
    public const int STATUS_WAIT    = 0;     # 等待
    public const int STATUS_TRAN    = 1;     # 传输中

    /**
     * @var array
     */
    public array $files = array();

    /**
     * @var string
     */
    protected string $currentTransferFilePath;

    /**
     * @var mixed
     */
    protected mixed $currentTransferFile;

    /**
     * @var int
     */
    protected int $status;

    /**
     * @var string
     */
    protected string $buffer = '';

    /**
     * @var string
     */
    protected string $boundary;


    /**
     * 请求单例
     * @var RequestSingle
     */
    protected RequestSingle $requestSingle;

    /**
     * 上传文件构造
     * @param RequestSingle $requestSingle
     * @param string        $boundary
     */
    public function __construct(RequestSingle $requestSingle, string $boundary)
    {
        $this->boundary      = $boundary;
        $this->status        = RequestUpload::STATUS_WAIT;
        $this->requestSingle = $requestSingle;
    }

    /**
     * 上下文推入
     * @param string $context
     * @return void
     * @throws RuntimeException(
     */
    public function push(string $context): void
    {
        $this->buffer .= $context;
        while ($this->buffer !== '' && $this->status !== RequestUpload::STATUS_ILLEGAL) {
            try {
                if ($this->status === RequestUpload::STATUS_WAIT && !$this->parseFileInfo()) {
                    break;
                }
                if (!$this->processTransmitting()) {
                    break;
                }
            } catch (RuntimeException $exception) {
                Output::exception($exception);
                $this->status = RequestUpload::STATUS_ILLEGAL;
                throw new RuntimeException('An exception occurred during the upload process');
            }
        }
    }

    /**
     * 解析文件信息
     * @return bool
     * @throws RuntimeException
     */
    private function parseFileInfo(): bool
    {
        $headerEndPosition = strpos($this->buffer, "\r\n\r\n");
        if ($headerEndPosition === false) {
            return false;
        }

        $header       = substr($this->buffer, 0, $headerEndPosition);
        $lines        = explode("\r\n", $header);
        $boundaryLine = array_shift($lines);
        if ($boundaryLine !== '--' . $this->boundary) {
            return false;
        }
        $this->buffer = substr($this->buffer, $headerEndPosition + 4);
        $fileInfo     = array();
        foreach ($lines as $line) {
            if (preg_match('/^Content-Disposition: form-data; name="([^"]+)"; filename="([^"]+)"$/i', $line, $matches)) {
                $fileInfo['name']     = $matches[1];
                $fileInfo['fileName'] = $matches[2];
            } elseif (preg_match('/^Content-Type: (.+)$/i', $line, $matches)) {
                $fileInfo['contentType'] = $matches[1];
            }
        }

        if (empty($fileInfo['name']) || empty($fileInfo['fileName'])) {
            throw new RuntimeException('File information is incomplete');
        }

        $fileInfo['path'] = $this->createNewFile();
        $this->files[]    = $fileInfo;
        $this->status     = RequestUpload::STATUS_TRAN;
        return true;
    }

    /**
     * 创建新文件
     * @return string
     */
    private function createNewFile(): string
    {
        $this->currentTransferFile = fopen($this->currentTransferFilePath, 'wb+');
        return $this->currentTransferFilePath;
    }

    /**
     * 处理传输中
     * @return bool
     */
    private function processTransmitting(): bool
    {
        $boundaryPosition = strpos($this->buffer, "\r\n--" . $this->boundary);
        if ($boundaryPosition !== false) {
            $remainingData        = substr($this->buffer, $boundaryPosition + 2);
            $nextBoundaryPosition = strpos($remainingData, "\r\n--" . $this->boundary);
            if ($nextBoundaryPosition === false && !str_starts_with($remainingData, '--')) {
                return false;
            }
            $content      = substr($this->buffer, 0, $boundaryPosition);
            $this->buffer = $remainingData;
            fwrite($this->currentTransferFile, $content);
            fclose($this->currentTransferFile);
            $this->status = RequestUpload::STATUS_WAIT;

        } else {
            fwrite($this->currentTransferFile, $this->buffer);
            $this->buffer = '';
        }
        return true;
    }
}
