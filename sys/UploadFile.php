<?php
declare(strict_types=1);

namespace sys;

class UploadFile {
    public ?string $filename = null;        # 本地文件名
    public ?string $mime = null;            # 文件类型
    public ?string $data = null;            # 文件内容|文件名
    public bool $is_file = false;           # 是否是文件

    /**
     * @param string|null $data 文件名或者文件内容,具体意义由 $is_file 决定
     * @param string|null $mime 文件类型
     * @param string|null $filename 本地文件名
     * @param bool $is_file 是否是文件
     */
    public function __construct(?string $data, ?string $mime, ?string $filename, bool $is_file = false)
    {
        $this->data = $data;
        $this->mime = $mime;
        $this->filename = $filename;
        $this->is_file = $is_file;
    }
}
