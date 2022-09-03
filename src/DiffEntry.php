<?php

namespace Xwillq\GitStageEditor;

class DiffEntry
{
    public function __construct(
        public string $src_mode,
        public string $dst_mode,
        public string $src_hash,
        public string $dst_hash,
        public string $status,
        public ?int $score,
        public string $src_path,
        public ?string $dst_path,
    ) {
    }
}
