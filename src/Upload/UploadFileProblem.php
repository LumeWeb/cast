<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * The class of artifact-file problem that prevented an upload from starting:
 * the path is not a readable regular file, or the file is empty.
 */
enum UploadFileProblem: string
{
    case NotFound = 'not_found';
    case Unreadable = 'unreadable';
    case Empty = 'empty';
}
