#!/usr/bin/env php
<?php
/*
 * (c) 2011 David Soria Parra <dsp at php dot net>
 *
 * Licensed under the terms of the MIT license.
 */

namespace Karma;

const KARMA_URL = '/repository/karma.git';
const KARMA_FILE = 'global_avail';

class GitReceiveHook
{
    const GIT_EXECUTABLE = 'git';
    const INPUT_PATTERN = '@^([0-9a-f]{40}) ([0-9a-f]{40}) (.+)$@i';

    /**
     * Returns the repository name.
     *
     * A repository name is the path to the repository without the .git.
     * e.g. php-src.git -> php-src
     *
     * @return string
     */
    public function getRepositoryName()
    {
        if (preg_match('@/([^/]+)\.git$@', $this->getRepositoryPath(), $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Returns the path to the current repository.
     *
     * Tries to determine the path of the current repository in which
     * the hook was invoked.
     *
     * @return string
     */
    public function getRepositoryPath()
    {
        $path = exec(sprintf('%s rev-parse --git-dir', self::GIT_EXECUTABLE));
        if (!is_dir($path)) {
            return false;
        }

        return realpath($path);
    }
    public function hookInput()
    {
        $parsed_input = [];
        while (!feof(STDIN)) {
            $line = fgets(STDIN);
            if (preg_match(self::INPUT_PATTERN, $line, $matches)) {
                $parsed_input[] = [
                    'old'     => $matches[1],
                    'new'     => $matches[2],
                    'refname' => $matches[3]];
            }
        }
        return $parsed_input;
    }

    public function getKarmaFile()
    {
        exec(
            sprintf('%s --git-dir=%s show master:%s',
                self::GIT_EXECUTABLE, KARMA_URL, KARMA_FILE), $output);
        return $output;
    }

    private function getReceivedPathsForRange($old, $new)
    {
        $repourl = $this->getRepositoryPath();
        $output  = [];
        exec(
            sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s..%s',
                self::GIT_EXECUTABLE, $repourl, $old, $new), $output);
        return $output;
    }

    public function getReceivedPaths()
    {
        $parsed_input = $this->hookInput();

        $paths = array_map(
            function ($input) {
                return $this->getReceivedPathsForRange($input['old'], $input['new']);
            },
            $parsed_input);

        /* remove empty lines, and flattern the array */
        $flattend = array_reduce($paths, 'array_merge', []);
        $paths    = array_filter($flattend);

        return array_unique($paths);
    }
}


function deny($reason)
{
    fwrite(STDERR, $reason . "\n");
    exit(1);
}

function accept($message)
{
    fwrite(STDOUT, $message . "\n");
    exit(0);
}

function get_karma_for_paths($username, array $paths, array $avail_lines)
{
    $access = array_fill_keys($paths, 'unavail');
    foreach ($avail_lines as $acl_line) {
        $acl_line = trim($acl_line);
        if ('' === $acl_line || '#' === $acl_line{0}) {
            continue;
        }

        @list($avail, $user_str, $path_str) = explode('|', $acl_line);

        $allowed_paths = explode(',', $path_str);
        $allowed_users = explode(',', $user_str);

        /* ignore lines which don't contain our users or apply to all users */
        if (!in_array($username, $allowed_users) && !empty($user_str)) {
            continue;
        }

        if (!in_array($avail, ['avail', 'unavail'])) {
            continue;
        }

        if (empty($path_str)) {
            $access = array_fill_keys($paths, $avail);
        } else {
            foreach ($access as $requested_path => $is_avail) {
                foreach ($allowed_paths as $path) {
                    if (fnmatch($path . '*', $requested_path)) {
                        $access[$requested_path] = $avail;
                    }
                }
            }
        }
    }

    return $access;
}

function get_unavail_paths($username, array $paths, array $avail_lines)
{
    return
        array_keys(
            array_filter(
                get_karma_for_paths($username, $paths, $avail_lines),
                function ($avail) {
                    return 'unavail' === $avail;
                }));
}


error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('UTC');
putenv("PATH=/usr/local/bin:/usr/bin:/bin");
putenv("LC_ALL=en_US.UTF-8");

$hook = new GitReceiveHook();
$requested_paths = $hook->getReceivedPaths();

if (empty($requested_paths)) {
    deny("We cannot figure out what you comitted!");
}

if (isset($_ENV['HTTP_AUTHORIZATION'])) {
    /* hacky hack is hacky */
    $auth  = $_ENV['HTTP_AUTHORIZATION'];
    $basic = base64_decode(explode(' ', $auth)[1]);
    $user  = explode(':', $basic)[0];
} else if (isset($_ENV['SSH_CONNECTION'])) {
    $user = $_ENV['USER'];
}

$prefix          = sprintf('php/%s/', $hook->getRepositoryName());
$avail_lines     = $hook->getKarmaFile();
$requested_paths = array_map(function ($x) use ($prefix) { return $prefix . $x;}, $requested_paths);
$unavail_paths   = get_unavail_paths($user, $requested_paths, $avail_lines);

if (!empty($unavail_paths)) {
    deny(sprintf(
        "You have insufficient Karma!\n" .
        "I'm sorry, I cannot allow you to write to\n" .
        "    %s\n" .
        "Have a nice day.",
        implode("\n    ", $unavail_paths)));
}

accept("Changesets accepted. Thank you.");
