<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

/**
 * Fires a GitHub `workflow_dispatch` — the mechanism behind the panel's
 * "Modul aktualisieren" button.
 *
 * A module cannot be swapped at runtime: the frontend products are composed at
 * BUILD time and the API is assembled into one bundle. Bringing a newer module
 * version into service therefore means re-running a pipeline:
 *
 *   - **frontend** → the product repo's `release.yml`. Because CI installs with
 *     `npm install --no-package-lock`, that rebuild resolves every caret range
 *     afresh, so it picks up the newest version **inside each pinned line**.
 *   - **backend**  → `tds-gateway-api`'s `release.yml`, which re-assembles the
 *     bundle from each service's and extension's `main`.
 *
 * Same shape as the CMS extensions' `RebuildTrigger` (plain ext-curl, no
 * Guzzle) but base-owned, and it REPORTS its outcome instead of logging it: the
 * admin pressed a button and must learn whether the run started.
 *
 * @see https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event
 */
final class WorkflowDispatcher
{
    /** `owner/name`, as GitHub spells it. */
    private const REPO = '#^[A-Za-z0-9._-]{1,100}/[A-Za-z0-9._-]{1,100}$#';

    /** A workflow FILE name — the dispatch endpoint takes the file, not the display name. */
    private const WORKFLOW = '#^[A-Za-z0-9._-]{1,100}\.ya?ml$#';

    /**
     * @param string $token A PAT with `workflow` scope. Empty ⇒ unconfigured.
     * @param callable|null $http Injected transport for tests:
     *        `fn(string $url, array $headers, string $body): array{status:int,body:string,error:string}`.
     */
    public function __construct(
        private readonly string $token,
        private $http = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Dispatch `$workflow` in `$repo` on `$ref`.
     *
     * The payload carries ONLY `ref`: the endpoint 422s on any input the
     * workflow does not declare, and none of the platform's release workflows
     * declare inputs.
     *
     * @return array{ok:bool, status:int, message:string}
     */
    public function dispatch(string $repo, string $workflow, string $ref = 'main'): array
    {
        $repo = trim($repo);
        $workflow = trim($workflow);
        $ref = trim($ref) !== '' ? trim($ref) : 'main';

        if ($this->token === '') {
            return $this->fail(0, 'Kein Deploy-Token hinterlegt.');
        }
        if (preg_match(self::REPO, $repo) !== 1) {
            return $this->fail(0, 'Ungültiges Repository (erwartet "owner/name").');
        }
        if (preg_match(self::WORKFLOW, $workflow) !== 1) {
            return $this->fail(0, 'Ungültiger Workflow (erwartet z. B. "release.yml").');
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            $repo,
            rawurlencode($workflow),
        );
        $payload = json_encode(['ref' => $ref], JSON_THROW_ON_ERROR);
        $res = $this->request($url, $payload);

        // GitHub answers 204 No Content on success.
        if ($res['status'] === 204) {
            return ['ok' => true, 'status' => 204, 'message' => sprintf('%s → %s gestartet.', $repo, $workflow)];
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return $this->fail($res['status'], 'Token abgelehnt (fehlender `workflow`-Scope oder keine SSO-Freigabe).');
        }
        if ($res['status'] === 404) {
            return $this->fail(404, 'Repository oder Workflow nicht gefunden.');
        }
        if ($res['status'] === 422) {
            return $this->fail(422, 'GitHub hat den Dispatch abgelehnt (Ref oder Inputs passen nicht).');
        }

        return $this->fail(
            $res['status'],
            $res['error'] !== '' ? $res['error'] : sprintf('GitHub antwortete HTTP %d.', $res['status']),
        );
    }

    /** @return array{ok:false, status:int, message:string} */
    private function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }

    /**
     * @return array{status:int, body:string, error:string}
     */
    private function request(string $url, string $payload): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Content-Type: application/json',
            'User-Agent: tds-core-frontend-api',
        ];

        if ($this->http !== null) {
            /** @var array{status:int, body:string, error:string} $res */
            $res = ($this->http)($url, $headers, $payload);
            return $res;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }
}
