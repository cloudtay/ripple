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

namespace Psc\Core\Http\Server\Upload;

use Psc\Core\Http\Server\Exception\FormatException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_pop;
use function array_shift;
use function explode;
use function fclose;
use function fopen;
use function fwrite;
use function preg_match;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/**
 * Http上传解析器
 */
class MultipartHandler
{
    private const STATUS_WAIT = 0;
    private const STATUS_TRAN = 1;

    /**
     * @var int
     */
    private int $status = MultipartHandler::STATUS_WAIT;

    /**
     * @var array
     */
    private array $task;

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
     * @param string $content
     * @return array
     * @throws FormatException
     */
    public function tick(string $content): array
    {
        $this->buffer .= $content;
        $result = array();
        while (!empty($this->buffer)) {
            if ($this->status === MultipartHandler::STATUS_WAIT) {
                if (!$info = $this->parseFileInfo()) {
                    break;
                }

                $this->status = MultipartHandler::STATUS_TRAN;

                if (!empty($info['fileName'])) {
                    $info['path'] = sys_get_temp_dir() . '/' . uniqid();
                    $info['stream'] = fopen($info['path'], 'wb+');
                    $this->task = $info;
                } else {
                    // If it's not a file, handle text data
                    $this->status = MultipartHandler::STATUS_WAIT;
                    $textContent = $this->parseTextContent();
                    if ($textContent !== false) {
                        $result[$info['name']] = $textContent;
                    }
                }
            }

            if ($this->status === MultipartHandler::STATUS_TRAN) {
                if (!$this->processTransmitting()) {
                    break;
                }
                $this->status = MultipartHandler::STATUS_WAIT;
                $result[$this->task['name']][] = new UploadedFile(
                    $this->task['path'],
                    $this->task['fileName'],
                    $this->task['contentType'],
                );
            }
        }

        return $result;
    }

    /**
     * @return array|false
     * @throws FormatException
     */
    private function parseFileInfo(): array|false
    {
        $headerEndPosition = strpos($this->buffer, "\r\n\r\n");
        if ($headerEndPosition === false) {
            return false;
        }

        $header = substr($this->buffer, 0, $headerEndPosition);
        $this->buffer = substr($this->buffer, $headerEndPosition + 4);

        $headerLines = explode("\r\n", $header);

        $boundaryLine = array_shift($headerLines);
        if (trim($boundaryLine) !== '--' . $this->boundary) {
            throw new FormatException('Boundary is invalid');
        }

        $name = '';
        $fileName = '';
        $contentType = '';

        while ($line = array_pop($headerLines)) {
            if (preg_match('/^Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?$/i', trim($line), $matches)) {
                $name = $matches[1];
                if (isset($matches[2])) {
                    $fileName = $matches[2];
                }
            } elseif (preg_match('/^Content-Type:\s*(.+)$/i', trim($line), $matches)) {
                $contentType = $matches[1];
            }
        }

        if ($name === '') {
            throw new FormatException('File information is incomplete');
        }

        if ($contentType && $contentType !== 'text/plain' && $fileName === '') {
            throw new FormatException('Content type must be text/plain for non-file fields');
        }

        return array(
            'name'        => $name,
            'fileName'    => $fileName,
            'contentType' => $contentType
        );
    }


    /**
     * 解析文本内容
     * @return string|false
     */
    private function parseTextContent(): string|false
    {
        $boundaryPosition = strpos($this->buffer, "\r\n--{$this->boundary}");
        if ($boundaryPosition === false) {
            return false;
        }

        $textContent = substr($this->buffer, 0, $boundaryPosition);
        $this->buffer = substr($this->buffer, $boundaryPosition + 2);
        return $textContent;
    }

    /**
     * 处理传输中
     * @return bool
     */
    private function processTransmitting(): bool
    {
        $mode = "\r\n--{$this->boundary}\r\n";

        $fileContent = $this->buffer;
        $boundaryPosition = strpos($fileContent, $mode);

        if ($boundaryPosition === false) {
            $boundaryPosition = strpos($fileContent, "\r\n--{$this->boundary}--");
        }

        if ($boundaryPosition !== false) {
            $fileContent = substr($fileContent, 0, $boundaryPosition);
            $this->buffer = substr($this->buffer, $boundaryPosition + 2);
            fwrite($this->task['stream'], $fileContent);
            return true;
        } else {
            $this->buffer = '';
            fwrite($this->task['stream'], $fileContent);
            return false;
        }
    }

    /**
     * @return void
     */
    public function cancel(): void
    {
        if ($this->status === MultipartHandler::STATUS_TRAN) {
            fclose($this->task['stream']);
        }
    }
}
