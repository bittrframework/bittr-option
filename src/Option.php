<?php

/**
 * Bittr
 *
 * @license
 *
 * New BSD License
 *
 * Copyright (c) 2017, bittrframework community
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *      1. Redistributions of source code must retain the above copyright
 *      notice, this list of conditions and the following disclaimer.
 *      2. Redistributions in binary form must reproduce the above copyright
 *      notice, this list of conditions and the following disclaimer in the
 *      documentation and/or other materials provided with the distribution.
 *      3. All advertising materials mentioning features or use of this software
 *      must display the following acknowledgement:
 *      This product includes software developed by the bittrframework.
 *      4. Neither the name of the bittrframework nor the
 *      names of its contributors may be used to endorse or promote products
 *      derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY bittrframework ''AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL BITTRFRAMEWORK COMMUNITY BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

declare(strict_types=1);

namespace Bittr;

use Closure;
use DirectoryIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class Option extends SplFileInfo
{
    /** @var bool  */
    private $force = false;
    /** @var bool  */
    private $silent = false;
    /** @var bool  */
    private $status = false;
    /** @var null|string  */
    private $base_path = null;
    /** @var string  */
    private $msg = false;
    /** @var int  */
    private $mode = 0755;
    /** @var null|string */
    private static $prefix = null;
    /** @var int  */
    public const LEAVES_ONLY = RecursiveIteratorIterator::LEAVES_ONLY;
    /** @var int  */
    public const SELF_FIRST = RecursiveIteratorIterator::SELF_FIRST;
    /** @var int  */
    public const CHILD_FIRST = RecursiveIteratorIterator::CHILD_FIRST;
    /** @var int  */
    public const NEST_NONE = 0;
    /** @var int  */
    public const NEST_SINGLE = 1;
    /** @var int  */
    public const NEST_MULTIPLE = 2;


    /**
     * Option constructor.
     *
     * @param string $file
     * @param bool   $force
     * @param bool   $suppress_errors
     * @param bool   $fetch_path
     */
    public function __construct(string $file, bool $force = false, bool $suppress_errors = false, bool $fetch_path = false)
    {
        $this->silent = $suppress_errors;
        $this->force = $force;

        if ($fetch_path)
        {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT)[0]['file'];
            $this->base_path = pathinfo($trace, PATHINFO_DIRNAME) . '/';
            $file =  $this->base_path . str_replace(['/', '\\'], '/', $file);
        }


        parent::__construct(self::$prefix . $file);
        $this->base_path = $this->getPath();
    }
    
    /**
     * Generate process message.
     * 
     * @param bool        $status
     * @param string      $type
     * @param string      $arg
     * @param string|null $arg2
     * @return $this
     */
    private function stat(bool $status, string $type, string $arg, string $arg2 = null)
    {
        $this->status = $status;
        $this->msg    = ($status ? 'complete' : 'failed')  . "::{$type} for ({$arg}" . ($arg2 ? ", {$arg2})" : ')');

        return $this;
    }

    /**
     * Opens a file or directory.
     *
     * @param Closure $closure
     * @param int     $nesting
     * @param int     $mode
     * @return \Bittr\Option
     */
    public function open(Closure $closure, int $nesting = Option::NEST_NONE, int $mode = Option::LEAVES_ONLY): Option
    {
        if ($this->isFile())
        {
            $closure($this->openFile('r+'));
        }
        else
        {
            $path = $this->getPathname();
            if ($nesting == self::NEST_NONE)
            {
                self::$prefix = "{$path}/";
                $closure(new DirectoryIterator($path));
                self::$prefix = null;
            }
            else
            {
                $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = ($nesting == self::NEST_SINGLE) ? $dir : new RecursiveIteratorIterator($dir, $mode);

                while($iterator->valid())
                {
                    $is_dir = $iterator->isDir();
                    if ($is_dir)
                    {
                        self::$prefix = "{$iterator->getPathname()}/";
                    }

                    $closure($iterator);
                    $iterator->next();

                    if ($is_dir)
                    {
                        self::$prefix = null;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Deletes a file or folder.
     *
     * @return $this
     */
    public function delete(): Option
    {
        $name = $this->getPathname();
        if ($this->isReadable())
        {
            if ($this->isFile())
            {
                $this->stat(@unlink($name), 'unlink', $name);
            }
            else
            {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($name, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                if ($iterator->valid() && ! $this->force)
                {
                    return $this->stat(false, 'rmdir(on non empty directory)', $name);
                }

                /** @var SplFileInfo $file_info */
                foreach ($iterator as $file_info)
                {
                    $path = $file_info->getRealPath();
                    $file_info->isFile()
                        ? $this->stat(@unlink($path), 'unlink', $path)
                        : $this->stat(@rmdir($path), 'rmdir', $path);
                }

                return $this->stat(@rmdir($name), 'rmdir', $name);
            }
        }
        else
        {
            return $this->stat(false, 'rm/unlink(on non existing file/dir or insufficient permission)', $name);
        }

        return $this;
    }

    /**
     * Creates a folder.
     *
     * @return $this
     */
    public function createFolder(): Option
    {
        $path = $this->getPathname();

        return $this->stat(@mkdir($path, $this->mode, true), 'mkdir', $path);
    }

    /**
     * Creates a file.
     *
     * @return $this
     */
    public function createFile(): Option
    {
        if ($this->isReadable() && $this->isFile())
        {
            if (! $this->force)
            {
                return $this;
            }

            $path = $this->getPathname();
            $this->stat(@unlink($path), 'unlink', $path);
        }
        $this->openFile('a');

        return $this;
    }

    /**
     * Renames a file or directory.
     *
     * @param string $new_name
     * @param bool   $cut
     * @return Option
     */
    public function rename(string $new_name, bool $cut = false): Option
    {
        if ($this->isReadable())
        {
            $current  = $this->getPathname();
            if (! $cut)
            {
                $new_name = rtrim($this->getRealPath(), $current) . "{$new_name}";
            }
            $this->stat(@rename($current, $new_name), 'rename', $current, $new_name);
        }

        return $this;
    }

    /**
     * Set mode (permission)
     *
     * @param int $mode
     * @return \Bittr\Option
     */
    public function setMode(int $mode): Option
    {
        $this->mode = $mode;

        return $this;
    }
    /**
     * Copies file or directories to specified directory.
     *
     * @param string $to
     * @return Option
     */
    public function copy(string $to): Option
    {
        $original = $this->getPathname();
        if ($this->isDir()) {

            try
            {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($original, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            }
            catch (Throwable $throwable)
            {
                return $this->stat(false, 'permission', $original);
            }

            if (! is_readable($to))
            {
                $this->stat(@mkdir($to, $this->mode, true), 'mkdir', $to);
            }

            /** @var SplFileInfo $file_or_dir */
            foreach ($iterator as $file_or_dir)
            {
                $source = $to . DIRECTORY_SEPARATOR . $file_or_dir->getFilename();

                if ($file_or_dir->isFile())
                {
                    if (! $file_or_dir->isReadable() || $this->force)
                    {
                        $file = $file_or_dir->getRealPath();
                        $this->stat(@copy($file, $source), 'copy', $file, $source);
                    }
                }
                else
                {
                    $this->stat(@mkdir($source, $this->mode), 'mkdir', $source);
                }
            }
        }
        else
        {
            $last = explode(DIRECTORY_SEPARATOR, $to);
            $file = trim(array_pop($last));
            $dir  = trim(implode(DIRECTORY_SEPARATOR, $last));

            if ($dir)
            {
                if (! is_readable($dir))
                {
                    $this->stat(@mkdir($dir, $this->mode, true), 'mkdir', $dir);
                }
                $dir .= DIRECTORY_SEPARATOR;
            }

            if (! $file)
            {
                $file = $this->getFilename();
            }

            $new_path = "{$dir}{$file}";
            if (is_readable($new_path) && ! $this->force)
            {
                $this->stat(false, 'exist', $new_path);
            }
            else
            {
                $this->stat(@copy($original, $new_path), 'mkdir', $original, $new_path);
            }
        }

        return $this;
    }

    /**
     * Cuts file or directories to specified directory
     *
     * @param string $to
     * @return $this
     */
    public function cut(string $to): Option
    {
        return $this->rename($to, true);
    }

    /**
     * Gets the status of last action.
     *
     * @return bool
     */
    public function status(): bool
    {
        return $this->status;
    }

    /**
     * Gets last exec message
     *
     * @return string
     */
    public function msg(): string
    {
        return $this->msg;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return parent::__toString(); // TODO: Change the autogenerated stub
    }
}
