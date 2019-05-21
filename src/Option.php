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
    /** @var bool  */
    private $as_cut = false;
    /** @var int  */
    private $mode = 0777;
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
     * Handles error and status.
     *
     * @param string $message
     * @return \Option
     */
    private function error(string $message): Option
    {
        $this->status = false;
        if (! $this->silent)
        {
            throw new RuntimeException($message);
        }

        return $this;
    }

    /**
     * Opens a file or directory.
     *
     * @param Closure $closure
     * @param int     $nesting
     * @param int     $mode
     * @return \Option
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
                $this->status = @unlink($name);
            }
            else
            {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($name, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                if ($iterator->valid() && ! $this->force)
                {
                    $this->error("The folder(\"{$name}\") you are trying to delete is not empty.");
                }
                else
                {
                    /** @var SplFileInfo $file_info */
                    foreach ($iterator as $file_info)
                    {
                        $file_info->isFile() ? unlink($file_info->getRealPath()) : rmdir($file_info->getRealPath());
                    }
                    $this->status = @rmdir($this->getPathname());
                }
            }
        }
        else
        {
            $this->error("\"{$name}\" is not a directory. Or insufficient access permission.");
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
        $this->status = true;
        if ($this->isReadable())
        {
            return $this->error("Directory: \"{$this->getFilename()}\" already exist in \"{$this->getPath()}\".");
        }
        else
        {
            $this->status = @mkdir($this->getPathname(), $this->mode, true);
        }

        return $this;
    }

    /**
     * Creates a file.
     *
     * @return $this
     */
    public function createFile(): Option
    {
        $this->status = true;
        if ($this->isReadable() && $this->isFile())
        {
            if ($this->force)
            {
                $this->status = @unlink($this->getPathname());
            }
            else
            {
                return $this->error("File: \"{$this->getFilename()}\" already exist in \"{$this->getPath()}\".");
            }
        }
        $this->openFile('a');

        return $this;
    }

    /**
     * Renames a file or directory.
     *
     * @param string $new_name
     * @return Option
     */
    public function rename(string $new_name): Option
    {
        if (! $this->isReadable())
        {
            $this->error("\"{$this->getPathname()}\" could not be found. Or insufficient access permission.");
        }
        else
        {
            $this->status = @rename($this->getPathname(), "{$this->getPath()}/{$new_name}");
        }

        return $this;
    }

    /**
     * Set mode (permission)
     *
     * @param int $mode
     * @return \Option
     */
    public function setMode(int $mode): Option
    {
        $this->mode = $mode;

        return $this;
    }

    private function move(SplFileInfo $from, string $to, array &$failed_copies): void
    {
        $old_file = $from->getRealPath();
        $path     = $to . str_replace($this->getRealPath(), '', $old_file);
        if (is_readable($path))
        {
            if (! $this->force)
            {
                $failed_copies['file'][] = $old_file;
                $this->error("File: \"{$from->getFilename()}\" already exist in \"{$to}\".");

                return;
            }
        }

        $this->status = @copy($old_file, $path);
        if ($this->as_cut)
        {
            $this->status = @unlink($old_file);
        }
    }

    /**
     * Copies file or directories to specified directory.
     *
     * @param string $to
     * @param bool   $contents_only
     * @param array  $failed_copies
     * @return Option
     */
    public function copy(string $to, bool $contents_only = false, array &$failed_copies = []): Option
    {
        $info = new SplFileInfo( "{$this->base_path}{$to}");
        $to   = $info->getPathname();
        $this->status = true;

        $name = $this->getPathname();
        // If dir copy
        if ($this->isDir())
        {
            if (! $info->isReadable())
            {
                mkdir($to, $this->mode, true);
            }

            try
            {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($name, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            }
            catch (Throwable $throwable)
            {
                return $this->error("\"{$name}\" could not be found. Or insufficient access permission.");
            }

            $dirs = [];
            if (! $contents_only)
            {
                if ($info->isReadable())
                {
                    $failed_copies['dir'][] = $this->getRealPath();
                    return $this->error("Directory: \"$to\" already exist.");
                }

                if ($this->as_cut)
                {
                    $dirs[] = $name;
                }
                $this->status = @mkdir($to, $this->mode, true);
            }

            /** @var SplFileInfo $file_info */
            foreach ($iterator as $file_info)
            {
                if ($file_info->isFile())
                {
                    $this->move($file_info, $to, $failed_copies);
                }
                else
                {
                    $path = $to . str_replace($this->getPathname(), '', $file_info->getPathname());
                    if (is_readable($path))
                    {
                        $failed_copies['dir'][] = $file_info->getRealPath();
                        return $this->error("Directory: \"{$file_info->getFilename()}\" already exist in \"{$to}\".");
                    }
                    else
                    {
                        $this->status = @mkdir($path);
                        if ($this->as_cut)
                        {
                            $dirs[] = $file_info->getPathname();
                        }
                    }
                }
            }

            if (! empty($dirs))
            {
                # will be populated on cut
                foreach (array_reverse($dirs) as $dir)
                {
                    $this->status = @rmdir($dir);
                }
            }
        }
        else
        {
            if (is_readable($to))
            {
                if (! $this->force)
                {
                    $failed_copies['file'][] = $name;
                    return $this->error("File: \"{$to}\" already exist.");
                }
            }
            $this->status = @copy($name, $to);
        }

        return $this;
    }

    /**
     * Cuts file or directories to specified directory
     *
     * @param string $to
     * @param bool   $create
     * @param array  $failed_copies
     * @return $this
     */
    public function cut(string $to, bool $create = true, array &$failed_copies = []): Option
    {
        $this->as_cut = true;
        $this->copy($to, $create, $failed_copies);

        return $this;
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
     * @return string
     */
    public function __toString()
    {
        return parent::__toString(); // TODO: Change the autogenerated stub
    }
}
