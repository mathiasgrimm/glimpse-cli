<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use LaravelZero\Framework\Commands\Command;
use MathiasGrimm\GlimpseCli\Commands\Concerns\GuardsApiErrors;
use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpseCli\Support\IgnoreFile;
use MathiasGrimm\GlimpseCli\Support\Paths;
use MathiasGrimm\GlimpseCli\Support\ScaffoldFile;
use MathiasGrimm\GlimpsePhp\ApiException;

class InitCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'init
        {--update-baseline : Seed the baseline by scanning the current directory (runs analyze . --update-baseline)}
        {--workflow : Add the check and optimize GitHub Actions workflow without prompting}
        {--workflow-mode= : Workflow to add without prompting: check or optimize (default)}
        {--force : Recreate .glimpseignore from its template even when it exists; select a workflow with --workflow or --workflow-mode to also recreate it}';

    protected $description = 'Set up the current directory for glimpse: a starter .glimpseignore, the baseline, and optionally a CI workflow';

    /**
     * Where the scaffolded GitHub Actions workflow lives, relative to
     * the project root.
     */
    public const WORKFLOW_PATH = '.github/workflows/glimpse.yml';

    /**
     * The starter .glimpseignore. One static template, no project-type
     * detection: the patterns are harmless on projects where they match
     * nothing.
     */
    private const IGNORE_TEMPLATE = <<<'GITIGNORE'
        # Created by glimpse init. Paths listed here are excluded from glimpse
        # scans (check, analyze), using gitignore syntax relative to this file.

        # Dependencies you do not control
        vendor/
        node_modules/

        # Generated and built assets
        public/build/
        dist/

        # Runtime state and user uploads (Laravel)
        storage/

        # Favicons and touch icons: tiny and format-constrained, not worth optimizing
        favicon.ico
        favicon*.png
        apple-touch-icon*.png

        GITIGNORE;

    /**
     * The scaffolded GitHub Actions workflow. A nowdoc, because the
     * YAML carries ${{ ... }} expressions a heredoc would interpolate.
     * The continuous-integration page of the docs (glimpseimg.com/docs)
     * shows this template as the copy-by-hand alternative; update it
     * when the template changes. The glimpseimg.com repo runs a
     * docs-drift workflow that compares the two and fails on mismatch.
     *
     * cpx installs the CLI through Composer and reuses its isolated copy.
     * PHP and cpx are installed by setup-php in both workflow modes.
     *
     * The GLIMPSE_TOKEN secret is optional. When the env var is empty
     * (fork pull requests never receive repository secrets, and a fresh
     * repository may not have set it yet), the CLI falls back to its
     * built-in public token, which supports image commands with shared
     * rate limits. A repository's own secret
     * gives higher limits.
     */
    public const WORKFLOW_TEMPLATE = <<<'YAML'
        name: Glimpse

        on:
          push:
            branches: [main]
          pull_request:

        permissions:
          contents: read

        jobs:
          check-images:
            runs-on: ubuntu-latest
            env:
              # Optional. Without it (for example on fork pull requests, which
              # never receive secrets) the CLI uses its built-in public token,
              # which supports image commands with shared limits.
              GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }}
            steps:
              - name: Set up PHP and cpx
                uses: shivammathur/setup-php@b604ade2a87db23f8871b7182e69ec5e75effb45 # v2
                with:
                  php-version: '8.5'
                  tools: cpx/cpx
                  coverage: none
              - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
              - name: Check images
                run: cpx --skip-local mathiasgrimm/glimpse-cli check .

        YAML;

    /**
     * Complete post-merge workflow, also published in the automatic optimization docs.
     */
    public const OPTIMIZE_TEMPLATE = <<<'YAML'
        name: Automatic image optimization by Glimpse

        on:
          pull_request_target:
            types: [closed]
          pull_request_review_comment:
            types: [created]

        permissions:
          contents: write
          pull-requests: write

        # In Settings → Actions → General → Workflow permissions, enable
        # "Allow GitHub Actions to create and approve pull requests".
        jobs:
          optimize-images:
            if: github.event_name == 'pull_request_target' && github.event.pull_request.merged == true
            uses: mathiasgrimm/glimpse-cli/.github/workflows/optimize-images.yml@v1
            secrets:
              GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }} # Optional

          skip-image:
            if: github.event_name == 'pull_request_review_comment' && github.event.comment.body == 'glimpse skip'
            uses: mathiasgrimm/glimpse-cli/.github/workflows/skip-image.yml@v1
        YAML;

    /**
     * Whether this run wrote the workflow file (created or recreated),
     * and whether it found and kept an existing one. Together with
     * "neither" they pick the next-steps variant.
     */
    private ?string $workflowWritten = null;

    private bool $workflowKept = false;

    public function handle(): int
    {
        return $this->runGuarded(function () {
            // The console application reuses the resolved command instance,
            // so the flags must not carry over from an earlier in-process run.
            $this->workflowWritten = null;
            $this->workflowKept = false;

            $mode = $this->requestedWorkflowMode();
            $root = Paths::root();

            // Order is load-bearing: the ignore file must exist before any
            // seed scan, because ImageFinder honors it; otherwise vendor/
            // images get recorded into the seeded baseline.
            $this->writeIgnoreFile($root);

            $exitCode = $this->setUpBaseline($root);

            $this->setUpWorkflow($root, $mode);

            // The empty-baseline warning and the seed hint key on what is
            // actually on disk after the run, not on what this run did: a
            // baseline kept from an earlier run may itself be empty, and a
            // failed refresh leaves a populated baseline intact.
            $this->printNextSteps(baselineEmpty: BaselineFile::load($root)->count() === 0);

            return $exitCode;
        });
    }

    private function writeIgnoreFile(string $root): void
    {
        $path = $root.'/'.IgnoreFile::FILENAME;
        $exists = is_file($path);

        if ($exists && ! $this->option('force')) {
            $this->line(IgnoreFile::FILENAME.' already exists, kept (use --force to recreate it from the starter template).');

            return;
        }

        ScaffoldFile::write($root, IgnoreFile::FILENAME, self::IGNORE_TEMPLATE, replace: $exists);

        $this->info($exists
            ? 'Recreated '.IgnoreFile::FILENAME.' from the starter template.'
            : 'Created '.IgnoreFile::FILENAME.'.');
    }

    /**
     * Create or seed the baseline. Seeding is opt-in: the --update-baseline
     * flag, or an interactive confirm defaulting to No, so a plain init
     * works with zero prerequisites (no token, no network). confirm()
     * returns the default when the input is non-interactive (-n) or stdin
     * is at end-of-file, the usual CI shape, so those runs fall through to
     * the empty baseline with zero API calls. Piped input that carries an
     * answer is read as that answer.
     *
     * Seeding accepts existing bytes rather than optimizing them. Keep the
     * prompt explicit because both workflow modes skip matching entries.
     */
    private function setUpBaseline(string $root): int
    {
        $path = $root.'/'.BaselineFile::FILENAME;
        $exists = is_file($path);

        $seed = (bool) $this->option('update-baseline');

        if (! $seed && ! $exists) {
            $seed = $this->confirm('Accept current images unchanged? Check and automatic optimization will skip them until they change (runs analyze . --update-baseline).', false);
        }

        if ($seed) {
            return $this->seedBaseline($path);
        }

        if ($exists) {
            $this->line(BaselineFile::FILENAME.' already exists, kept.');

            return self::SUCCESS;
        }

        BaselineFile::load($root)->save($root);
        $this->info('Created '.BaselineFile::FILENAME.' (empty).');

        return self::SUCCESS;
    }

    /**
     * Seed by delegating to analyze, reusing its locking, hashing, pruning,
     * and reporting; its exit code is passed through. A failed seed that
     * aborted before the baseline was saved still leaves an empty baseline
     * behind, so the project ends up scaffolded either way, but the run
     * fails because a requested seed did not happen.
     */
    private function seedBaseline(string $path): int
    {
        $exitCode = $this->call('analyze', ['input' => '.', '--update-baseline' => true]);

        if ($exitCode === self::SUCCESS) {
            return self::SUCCESS;
        }

        if (! is_file($path)) {
            BaselineFile::load(dirname($path))->save(dirname($path));
            $this->warn('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline');
        }

        return self::FAILURE;
    }

    /**
     * Validate before scaffolding or seeding. An explicit mode also requests
     * the workflow, and overrides the default implied by --workflow.
     */
    private function requestedWorkflowMode(): ?string
    {
        $mode = $this->option('workflow-mode');

        if (($mode !== null || $this->input->hasParameterOption('--workflow-mode', true))
            && ! in_array($mode, ['check', 'optimize'], true)) {
            throw new ApiException('--workflow-mode must be check or optimize.');
        }

        return $mode ?? ($this->option('workflow') ? 'optimize' : null);
    }

    private function setUpWorkflow(string $root, ?string $mode): void
    {
        $path = $root.'/'.self::WORKFLOW_PATH;
        $exists = is_file($path);

        if ($exists && ($mode === null || ! $this->option('force'))) {
            $this->line(self::WORKFLOW_PATH.' already exists, kept (use --workflow-mode=check or --workflow-mode=optimize with --force to replace it).');
            $this->workflowKept = true;

            return;
        }

        if ($mode === null && $this->isGitRoot($root) && $this->input->isInteractive()) {
            $this->line('Check and optimize: after a PR merges, open an optimization PR. GLIMPSE_TOKEN is optional.');
            $this->line('Check only: check pull requests and pushes to main. The token is optional.');
            $choice = $this->choice('Which GitHub Actions workflow would you like?', [
                'Check and optimize',
                'Check only',
                'No workflow',
            ], 0);

            // Symfony makes input non-interactive when it reaches EOF and
            // returns the default answer. EOF must not opt into automation.
            if ($this->input->isInteractive()) {
                $mode = match ($choice) {
                    'Check and optimize' => 'optimize',
                    'Check only' => 'check',
                    default => null,
                };
            }
        }

        if ($mode === null) {
            return;
        }

        $template = $mode === 'check' ? self::WORKFLOW_TEMPLATE : self::OPTIMIZE_TEMPLATE;
        ScaffoldFile::write($root, self::WORKFLOW_PATH, $template, replace: $exists);
        $this->info($exists
            ? 'Recreated '.self::WORKFLOW_PATH.' from the starter template.'
            : 'Created '.self::WORKFLOW_PATH.'.');
        $this->workflowWritten = $mode;
    }

    /**
     * Whether the root is a git repository: .git is a directory in a
     * normal clone and a file in a worktree.
     */
    private function isGitRoot(string $root): bool
    {
        return file_exists($root.'/.git');
    }

    private function printNextSteps(bool $baselineEmpty): void
    {
        if ($baselineEmpty && $this->workflowWritten !== null) {
            $this->newLine();
            $this->warn($this->workflowWritten === 'check'
                ? 'The baseline is empty. The check-only workflow will fail on images above the threshold.'
                : 'The baseline is empty. After the next PR merges, automatic optimization will process all images reported by check and open a PR.');
        }

        $steps = ['Review '.IgnoreFile::FILENAME.' and tune the patterns for your project.'];

        if ($baselineEmpty) {
            $steps[] = 'To accept current images unchanged and skip them until they change: glimpse analyze . --update-baseline';
        }

        if ($this->workflowWritten !== null) {
            $steps[] = 'Optional: set the GLIMPSE_TOKEN secret for higher rate limits: gh secret set GLIMPSE_TOKEN';

            if ($this->workflowWritten === 'optimize') {
                $steps[] = 'Install the workflow on your default branch and allow GitHub Actions to create pull requests. Generated PR checks require approval.';
                $steps[] = 'Optimization uses quality 85, keeps formats and filenames, and may remove metadata. See https://glimpseimg.com/docs/cli/automatic-optimization';
            }
            $steps[] = 'Commit '.IgnoreFile::FILENAME.', '.BaselineFile::FILENAME.', and '.self::WORKFLOW_PATH.'.';
        } else {
            $steps[] = 'Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.';

            $steps[] = $this->workflowKept
                ? 'Review '.self::WORKFLOW_PATH.'; GLIMPSE_TOKEN is optional in both workflow modes.'
                : 'Gate new images in CI: glimpse check .  (see https://glimpseimg.com/docs/cli/continuous-integration)';
        }

        $this->newLine();
        $this->line('Next steps:');

        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step);
        }
    }
}
