<?php
namespace Git;

class PostReceiveHook extends ReceiveHook
{

    private $pushAuthor = '';
    private $mailingList = '';
    private $emailprefix = '';


    private $refs = array();
    private $revisions = array();

    private $allBranches = array();


    public function __construct($basePath, $pushAuthor, $mailingList, $emailprefix)
    {
        parent::__construct($basePath);

        $this->pushAuthor = $pushAuthor;
        $this->mailingList = $mailingList;
        $this->emailprefix = $emailprefix;

        $this->allBranches = $this->getAllBranches();
    }

    private function getAllBranches()
    {
        return explode("\n", $this->execute('git for-each-ref --format="%%(refname)" "refs/heads/*"'));
    }


    private function execute($cmd)
    {
        $args = func_get_args();
        array_shift($args);
        $cmd = vsprintf($cmd, $args);
        $output = shell_exec($cmd);
        return $output;
    }

    public function process()
    {
        $this->refs = $this->hookInput();

        //send mails per ref push
        foreach ($this->refs as $ref) {
            if ($ref['reftype'] == self::REF_TAG) {
                $this->sendTagMail($ref);
            } elseif ($ref['reftype'] == self::REF_BRANCH){
                $this->sendBranchMail($ref);
            }
        }

        // TODO: For new branches we must check if this branch was
        // cloned from other branch in this push - it's especial case

        foreach ($this->revisions as $revision => $branches) {
            // check if it commit was already in other branches
            if (!$this->isRevExistsInBranches($revision, array_diff($this->allBranches, $branches))) {
                $this->sendCommitMail($revision);
            }
        }

    }

    private function sendBranchMail(array $branch)
    {

        if ($branch['changetype'] == self::TYPE_UPDATED) {
            $title = "Branch " . $branch['refname'] . " was updated";
        } elseif ($branch['changetype'] == self::TYPE_CREATED) {
            $title = "Branch " . $branch['refname'] . " was created";
        } else {
            $title = "Branch " . $branch['refname'] . " was deleted";
        }
        $message = $title . "\n\n";


        if ($branch['changetype'] != self::TYPE_DELETED) {

            if ($branch['changetype'] == self::TYPE_UPDATED) {
                // check if push was with --forced option
                if ($replacedRevisions = $this->getRevisions($branch['new'] . '..' . $branch['old'])) {
                    $message .= "Discarded revisions: \n" . implode("\n", $replacedRevisions) . "\n";
                }

                // git rev-list old..new
                $revisions = $this->getRevisions($branch['old'] . '..' . $branch['new']);

            } else {
                // for new branch we write log about new commits only
                $revisions = $this->getRevisions($branch['new']. ' --not ' . implode(' ', $this->allBranches));
            }

            $this->cacheRevisions($branch['refname'], $revisions);

            if (count($revisions)) {
                $message .= "--------LOG--------\n";
                foreach ($revisions as $revision) {
                    $diff = $this->execute(
                        'git diff-tree --stat --pretty=medium -c %s',
                        $revision
                    );

                    $message .= $diff."\n\n";
                }
            }
        }

        $this->mail($this->emailprefix . '[push] ' . $title , $message);
    }


    private function cacheRevisions($branchName, array $revisions)
    {
        //TODO: add mail order from older commit to newer
        foreach ($revisions as $revision)
        {
            $this->revisions[$revision][] = $branchName;
        }
    }


    private function sendTagMail(array $tag)
    {

        if ($tag['changetype'] == self::TYPE_UPDATED) {
            $title = "Tag " . $tag['refname'] . " was updated";
        } elseif ($tag['changetype'] == self::TYPE_CREATED) {
            $title = "Tag " . $tag['refname'] . " was created";
        } else {
            $title = "Tag " . $tag['refname'] . " was deleted";
        }

        $message = $title . "\n\n";

        if ($tag['changetype'] != self::TYPE_DELETED) {
            $message .= "Tag info:\n";
            $isAnnotatedNewTag = $this->isAnnotatedTag($tag['refname']);
            if ($isAnnotatedNewTag) {
                $message .= $this->getAnnotatedTagInfo($tag['refname']) ."\n";
            } else {
                $message .= $this->getTagInfo($tag['new']) ."\n";
            }
        }
        if ($tag['changetype'] != self::TYPE_CREATED) {
            $message .= "Old tag sha: \n" . $tag['old'];
        }

        $this->mail($this->emailprefix . '[push] ' . $title , $message);
    }

    private function getTagInfo($tag)
    {
        $info = "Target:\n";
        $info .= $this->execute('git diff-tree --stat --pretty=medium -c %s', $tag);
        return $info;
    }

    private function getAnnotatedTagInfo($tag)
    {
        $tagInfo = $this->execute('git for-each-ref --format="%%(*objectname) %%(taggername) %%(taggerdate)" %s', $tag);
        list($target, $tagger, $taggerdate) = explode(' ', $tagInfo);

        $info = "Tagger: " . $tagger . "\n";
        $info .= "Date: " . $taggerdate . "\n";
        $info .= $this->execute("git cat-file tag %s | sed -e '1,/^$/d'", $tag)."\n";
        $info .= "Target:\n";
        $info .= $this->execute('git diff-tree --stat --pretty=medium -c %s', $target);
        return $info;
    }

    private function isAnnotatedTag($rev)
    {
        return trim($this->execute('git for-each-ref --format="%%(objecttype)" %s', $rev)) == 'tag';
    }


    private function getRevisions($revRange)
    {
        $output = $this->execute(
            'git rev-list %s',
            $revRange
        );
        $revisions = $output ? explode("\n", trim($output)) : array();
        return $revisions;
    }


    private function sendCommitMail($revision)
    {
        $title = "Commit " . $revision . " was added";
        $message = $title . "\n\n";


        $info = $this->execute('git diff-tree --stat --pretty=fuller -c %s', $revision);

        $message .= $info ."\n\n";

        $message .= "--------DIFF--------\n";

        $diff = $this->execute('git diff-tree -c -p %s', $revision);

        $message .= $diff ."\n\n";

        $this->mail($this->emailprefix . '[commit] ' . $title , $message);
    }


    private function mail($subject, $message) {
        $headers = array(
            'From: ' . $this->pushAuthor . '@php.net',
            'Reply-To: ' . $this->pushAuthor . '@php.net'
        );

        mail($this->mailingList, $subject, $message, implode("\r\n", $headers));
    }


    private function isRevExistsInBranches($revision, array $branches) {
        return !(bool) $this->execute('git rev-list --max-count=1 %s --not %s', $revision, implode(' ', $branches));
    }

}
