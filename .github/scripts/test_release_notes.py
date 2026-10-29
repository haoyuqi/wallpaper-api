import importlib.util
import http.client
import io
import json
import socket
import subprocess
import tempfile
import unittest
import urllib.error
from unittest.mock import patch
from pathlib import Path

SPEC = importlib.util.spec_from_file_location('release_notes', Path(__file__).with_name('release-notes.py'))
notes = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(notes)


class ReleaseNotesTest(unittest.TestCase):
    def setUp(self):
        sleeper = patch.object(notes.time, 'sleep')
        sleeper.start()
        self.addCleanup(sleeper.stop)

    def test_transient_failures_stop_after_three_attempts_without_logs(self):
        for failure in [urllib.error.URLError(socket.gaierror('private endpoint'))]:
            with self.subTest(failure=type(failure).__name__), patch.object(notes.HTTP, 'open', side_effect=failure) as call, patch('sys.stderr', new_callable=io.StringIO) as log:
                with self.assertRaises(notes.GenerationError):
                    notes.complete('https://example.test/v1', 'secret', 'model', '', '')
                self.assertEqual(3, call.call_count)
                self.assertEqual('', log.getvalue())

    def context(self):
        return {'sources': [{'id': 'pr:1', 'title': 'fix: behavior', 'body': 'Full description',
                             'url': 'https://github.com/owner/repo/pull/1'}], 'runtime_changes': {}}

    def content(self):
        return json.dumps({'entries': [{'category': 'Fixes', 'sources': ['pr:1'],
                                       'en': 'Fix behavior.', 'zh': '修复行为异常。'}]}, ensure_ascii=False)

    def test_one_request_returns_both_languages_and_complete_sources(self):
        context = self.context()
        context['sources'][0]['body'] = 'full body ' * 20000
        with patch.object(notes, 'complete', return_value=self.content()) as call:
            result = notes.generate(context, ('https://example.test/v1', 'private-key', 'private-model'))
        call.assert_called_once()
        self.assertEqual(context, json.loads(call.call_args.args[-1]))
        self.assertIn('<!-- lang:zh -->', result)
        self.assertIn('修复行为异常。', result)
        self.assertIn('完整仓库资料', notes.BILINGUAL_PROMPT)
        self.assertIn('来源覆盖不是实现细节覆盖', notes.BILINGUAL_PROMPT)

    def test_missing_configuration_and_generation_failure_have_no_fallback(self):
        with patch.object(notes, 'complete') as call:
            with self.assertRaises(notes.GenerationError):
                notes.generate(self.context(), ('', '', ''))
            call.assert_not_called()
        for failure in [notes.GenerationError('invalid_response'), ValueError('private detail')]:
            with patch.object(notes, 'complete', side_effect=failure) as call:
                with self.assertRaises(ValueError):
                    notes.generate(self.context(), ('url', 'private-key', 'private-model'))
                call.assert_called_once()

    def test_invalid_content_or_configuration_echo_stops_generation(self):
        for content in ['private-key', '<!-- lang:zh -->', '{}', 'English only']:
            with patch.object(notes, 'complete', return_value=content) as call:
                with self.assertRaises(ValueError):
                    notes.generate(self.context(), ('https://example.test/v1', 'private-key', 'private-model'))
                call.assert_called_once()

    def test_timeout_disconnect_and_unknown_network_errors_never_retry(self):
        for failure in [TimeoutError(), http.client.IncompleteRead(b'partial'),
                        http.client.RemoteDisconnected(), ConnectionResetError(),
                        urllib.error.URLError('unknown'), urllib.error.URLError(TimeoutError())]:
            with self.subTest(failure=type(failure).__name__), patch.object(notes.HTTP, 'open', side_effect=failure) as call:
                with self.assertRaises(notes.GenerationError):
                    notes.complete('https://example.test/v1', 'key', 'model', '', '')
                self.assertEqual(1, call.call_count)

    def test_truncated_or_missing_finish_reason_never_retries(self):
        for reason in ['length', None, 'content_filter']:
            response = io.BytesIO(json.dumps({'choices': [{'finish_reason': reason, 'message': {'content': self.content()}}]}).encode())
            with patch.object(notes.HTTP, 'open', return_value=response) as call:
                with self.assertRaises(notes.GenerationError):
                    notes.complete('https://example.test/v1', 'key', 'model', '', '')
                call.assert_called_once()

    def test_transport_limit_fails_without_sending_or_shortening(self):
        with patch.object(notes.HTTP, 'open') as call:
            with self.assertRaises(notes.GenerationError):
                notes.complete('https://example.test/v1', 'key', 'model', '', 'a' * 1_000_001)
            call.assert_not_called()

    def test_cli_failure_never_writes_publishable_notes_or_private_details(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'notes.md'
            with patch('sys.argv', ['release-notes.py', 'generate', '--tag', 'v1.2.3', '--output', str(output)]), patch.object(notes, 'validate', return_value='a' * 40), patch.object(notes, 'git', return_value=''), patch.object(notes, 'release_context', side_effect=ValueError('private endpoint secret')), patch('sys.stderr', new_callable=io.StringIO) as log:
                with self.assertRaises(SystemExit):
                    notes.main()
            self.assertFalse(output.exists())
            self.assertNotIn('private', log.getvalue())

    def test_server_error_recovers_on_retry(self):
        failure = urllib.error.HTTPError('url', 503, 'unavailable', {}, io.BytesIO(b'private response'))
        response = io.BytesIO(json.dumps({'choices': [{'finish_reason': 'stop', 'message': {'content': 'Recovered'}}]}).encode())
        with patch.object(notes.HTTP, 'open', side_effect=[failure, response]) as call:
            self.assertEqual('Recovered', notes.complete('https://example.test/v1', 'key', 'model', '', ''))
        self.assertEqual(2, call.call_count)

    def test_release_tags_require_v_and_three_canonical_numbers(self):
        self.assertEqual((3, 0, 3), notes.version('v3.0.3'))
        self.assertEqual((3, 0, 2), notes.version('3.0.2', legacy=True))
        for value in ['3.0.3', 'v03.0.3', 'v1.2', 'v1.2.3-rc.1', '-x', 'v1.2.3\n']:
            with self.assertRaises(ValueError):
                notes.version(value)

    def test_previous_version_preserves_legacy_name_and_ignores_newer_tags(self):
        tags = ['v1.0.0', '3.0.2', 'v3.0.4', 'v3.0.3', 'random', 'v3.0.2-rc.1']
        self.assertEqual('3.0.2', notes.previous_tag('v3.0.3', tags))
        self.assertIsNone(notes.previous_tag('v0.1.0', tags))
        self.assertEqual('v3.0.2', notes.previous_tag('v3.0.3', ['3.0.2', 'v3.0.2']))

    def test_endpoint_and_request_use_high(self):
        response = io.BytesIO(json.dumps({'choices': [{'finish_reason': 'stop', 'message': {'content': ' Notes '}}]}).encode())
        with patch.object(notes.HTTP, 'open', return_value=response) as request:
            self.assertEqual('Notes', notes.complete('https://example.test/v1/', 'secret', 'model', 'system', 'input'))
        outgoing = request.call_args.args[0]
        self.assertEqual('https://example.test/v1/chat/completions', outgoing.full_url)
        self.assertEqual('release-notes-generator/1.0', outgoing.get_header('User-agent'))
        payload = json.loads(outgoing.data)
        self.assertEqual('high', payload['reasoning_effort'])
        self.assertFalse(payload['stream'])

    def test_insecure_or_credentialed_urls_are_rejected(self):
        for base in ['http://example.test/v1', 'https://user:pass@example.test/v1', 'https://example.test/v1?token=a']:
            with self.assertRaises(ValueError):
                notes.complete(base, 'key', 'model', '', '')

    def test_empty_or_malformed_responses_are_errors(self):
        for payload in [{}, {'choices': ['bad']}, {'choices': [{'message': {'content': ' '}}]}, {'choices': [{'message': {'content': ['bad']}}]}]:
            with patch.object(notes.HTTP, 'open', return_value=io.BytesIO(json.dumps(payload).encode())):
                with self.assertRaises(ValueError):
                    notes.complete('https://example.test/v1', 'key', 'model', '', '')

    def test_temporary_rate_limit_retries_with_bound(self):
        def limited():
            return urllib.error.HTTPError('url', 429, 'limited', {}, io.BytesIO(b'{"error":{"code":"rate_limit"}}'))
        with patch.object(notes.HTTP, 'open', side_effect=[limited(), limited(), limited()]) as call, patch.object(notes.time, 'sleep') as sleep:
            with self.assertRaises(ValueError):
                notes.complete('https://example.test/v1', 'key', 'model', '', '')
        self.assertEqual(3, call.call_count)
        self.assertEqual(2, sleep.call_count)

    def test_daily_quota_and_authentication_are_not_retried(self):
        quota = urllib.error.HTTPError('url', 429, 'limited', {}, io.BytesIO(b'{"error":{"message":"Daily token quota exhausted"}}'))
        for error in [quota, urllib.error.HTTPError('url', 401, 'unauthorized', {}, io.BytesIO(b''))]:
            with patch.object(notes.HTTP, 'open', side_effect=error) as call:
                with self.assertRaises((ValueError, TimeoutError)):
                    notes.complete('https://example.test/v1', 'key', 'model', '', '')
            self.assertEqual(1, call.call_count)

    def test_http_failure_reports_only_the_safe_status(self):
        unauthorized = urllib.error.HTTPError(
            'https://private.example/v1/chat/completions',
            401,
            'unauthorized',
            {},
            io.BytesIO(b'{"error":{"message":"bad secret-key for private.example"}}'),
        )
        with patch.object(notes.HTTP, 'open', side_effect=unauthorized):
            with self.assertRaises(notes.GenerationError) as raised:
                notes.complete('https://private.example/v1', 'secret-key', 'model', '', '')
        self.assertEqual('http_status_401', raised.exception.reason)
        self.assertNotIn('private.example', str(raised.exception))
        self.assertNotIn('secret-key', str(raised.exception))

    def test_network_failure_reports_a_generic_safe_category(self):
        failure = notes.urllib.error.URLError(socket.gaierror('private.example'))
        with patch.object(notes.HTTP, 'open', side_effect=failure):
            with self.assertRaises(notes.GenerationError) as raised:
                notes.complete('https://private.example/v1', 'secret-key', 'model', '', '')
        self.assertEqual('network_dns', raised.exception.reason)
        self.assertNotIn('private.example', str(raised.exception))

    def test_incomplete_response_reports_a_safe_category(self):
        with patch.object(notes.HTTP, 'open', side_effect=http.client.IncompleteRead(b'partial')):
            with self.assertRaises(notes.GenerationError) as raised:
                notes.complete('https://example.test/v1', 'secret-key', 'model', '', '')
        self.assertEqual('incomplete_response', raised.exception.reason)
        self.assertNotIn('secret-key', str(raised.exception))

    def test_incomplete_http_error_body_does_not_escape(self):
        class BrokenBody:
            def read(self, _size):
                raise http.client.IncompleteRead(b'partial')

            def close(self):
                pass

        response = urllib.error.HTTPError('url', 401, 'unauthorized', {}, BrokenBody())
        with patch.object(notes.HTTP, 'open', side_effect=response):
            with self.assertRaises(notes.GenerationError) as raised:
                notes.complete('https://example.test/v1', 'secret-key', 'model', '', '')
        self.assertEqual('http_status_401', raised.exception.reason)

    def test_redirects_are_not_followed_with_credentials(self):
        with self.assertRaises(urllib.error.HTTPError) as raised:
            notes.NoRedirect().redirect_request(notes.urllib.request.Request('https://example.test'), None, 302, 'found', {}, 'https://other.test')
        raised.exception.close()

    def test_only_direct_dependencies_are_selected_from_lockfile(self):
        manifest = {'require': {'livewire/livewire': '^4.4'}, 'require-dev': {'phpunit/phpunit': '^12'}}
        lock = {
            'packages': [
                {'name': 'livewire/livewire', 'version': 'v4.4.8'},
                {'name': 'transitive/package', 'version': '1.0.0'},
            ],
            'packages-dev': [{'name': 'phpunit/phpunit', 'version': '12.4.1'}],
        }
        self.assertEqual(
            {'livewire/livewire': 'v4.4.8', 'phpunit/phpunit': '12.4.1'},
            notes.direct_dependency_versions(lock, manifest),
        )

    def test_workflow_highlights_extract_runtime_and_coverage_values(self):
        workflow = "node-version: '^24.22.1'\nphp-version: '8.4'\nMIN_LINE_COVERAGE: '100'\n"
        with patch.object(notes, 'git', return_value=workflow):
            self.assertEqual(
                {
                    'runtime_versions': {'node': ['^24.22.1'], 'php': ['8.4']},
                    'minimum_line_coverage': ['100'],
                },
                notes.workflow_highlights('a' * 40, ['.github/workflows/ci.yml']),
            )


REPO = 'haoyuqi/wallpaper-api'
A, B, C = 'a' * 40, 'b' * 40, 'c' * 40


def pull(number=1, merge=B, body='full description'):
    return {'number': number, 'title': 'fix: behavior', 'body': body,
            'merged_at': '2026-10-29T00:00:00Z', 'merge_commit_sha': merge,
            'base': {'repo': {'full_name': REPO}}}


class SourcesTest(unittest.TestCase):
    def history(self, *args):
        if args[0] == 'log':
            return A + '\n' + B
        return 'fix: behavior\0' + 'complete body ' * 500

    def test_collects_full_pr_once_and_preserves_unmatched_commit(self):
        description = 'complete PR body ' * 1000
        with patch.object(notes, 'github_pulls', side_effect=[[], [pull(body=description)]]):
            result = notes.collect_sources('v1..v2', REPO, self.history)
        self.assertEqual(['commit:' + A, 'pr:1'], [row['id'] for row in result])
        self.assertEqual('complete body ' * 500, result[0]['body'])
        self.assertEqual(description, result[1]['body'])
        self.assertEqual('https://github.com/' + REPO + '/pull/1', result[1]['url'])

    def test_deduplicates_pr_associated_with_multiple_commits(self):
        with patch.object(notes, 'github_pulls', return_value=[pull()]):
            result = notes.collect_sources('range', REPO, self.history)
        self.assertEqual(1, len(result))
        self.assertEqual('pr:1', result[0]['id'])
        self.assertEqual([A, B], result[0]['commits'])

    def test_unmerged_later_and_foreign_prs_remain_direct_commits(self):
        unmerged = pull(); unmerged['merged_at'] = None
        foreign = pull(); foreign['base']['repo']['full_name'] = 'other/repo'
        with patch.object(notes, 'github_pulls', return_value=[unmerged, foreign, pull(merge=C)]):
            result = notes.collect_sources('range', REPO, self.history)
        self.assertEqual(['commit:' + A, 'commit:' + B], [row['id'] for row in result])

    def test_includes_merge_commits_in_history_and_rejects_api_failure(self):
        with patch.object(notes, 'github_pulls', side_effect=ValueError('failure')):
            with self.assertRaises(ValueError):
                notes.collect_sources('range', REPO, self.history)
        calls = []
        def history(*args):
            calls.append(args)
            return self.history(*args)
        with patch.object(notes, 'github_pulls', return_value=[]):
            notes.collect_sources('range', REPO, history)
        self.assertNotIn('--no-merges', calls[0])

    def test_github_api_uses_all_pages_and_discards_stderr(self):
        pages = json.dumps([[pull()], [pull(number=2)]]).encode()
        with patch.object(notes.subprocess, 'check_output', return_value=pages) as run:
            self.assertEqual(2, len(notes.github_pulls(REPO, A)))
        command = run.call_args.args[0]
        self.assertIn('--paginate', command)
        self.assertIn('--slurp', command)
        self.assertEqual(subprocess.DEVNULL, run.call_args.kwargs['stderr'])

    def test_bad_api_response_and_repository_are_rejected(self):
        for raw in [b'{}', b'[{}]', b'[[1]]']:
            with patch.object(notes.subprocess, 'check_output', return_value=raw):
                with self.assertRaises(ValueError):
                    notes.github_pulls(REPO, A)
        with patch.object(notes.subprocess, 'check_output') as run:
            with self.assertRaises(ValueError):
                notes.collect_sources('range', '../unsafe', self.history)
            run.assert_not_called()

    def test_runtime_requirements_include_add_change_and_removal(self):
        before = {'require': {'php': '^8.4', 'ext-old': '*', 'vendor/package': '^1'}}
        after = {'require': {'php': '~8.4.27 || ^8.5.12', 'ext-new': '*', 'vendor/package': '^2'}}
        def history(*args):
            return json.dumps(before if args[-1].startswith('old:') else after)
        result = notes.runtime_changes('old', 'new', history)
        self.assertEqual({'before': '^8.4', 'after': '~8.4.27 || ^8.5.12'}, result['php'])
        self.assertEqual({'before': '*', 'after': None}, result['ext-old'])
        self.assertEqual({'before': None, 'after': '*'}, result['ext-new'])
        self.assertNotIn('vendor/package', result)

    def test_runtime_manifest_is_required_and_strict(self):
        for raw in ['invalid', '[]', '{"require": []}', '{"require":{"php":8.4}}']:
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                notes.runtime_changes(None, 'new', lambda *args: raw)
        with self.assertRaises(subprocess.CalledProcessError):
            notes.runtime_changes('old', 'new', lambda *args: (_ for _ in ()).throw(subprocess.CalledProcessError(1, 'git')))

    def test_initial_release_and_unchanged_runtime(self):
        manifest = lambda *args: '{"require":{"php":"^8.4"}}'
        self.assertEqual({'php': {'before': None, 'after': '^8.4'}}, notes.runtime_changes(None, 'new', manifest))
        self.assertEqual({}, notes.runtime_changes('old', 'new', manifest))


class GitFixtureTest(unittest.TestCase):
    def test_merge_squash_and_rebased_commits_use_actual_range(self):
        with tempfile.TemporaryDirectory() as directory:
            def git(*args):
                return subprocess.check_output(['git', '-C', directory, *args], text=True, stderr=subprocess.DEVNULL).rstrip('\n')
            git('init', '-b', 'master')
            git('config', 'user.name', 'Fixture')
            git('config', 'user.email', 'fixture@example.test')
            git('commit', '--allow-empty', '-m', 'initial')
            git('tag', 'v1.0.0')
            git('checkout', '-b', 'feature')
            Path(directory, 'a').write_text('feature')
            git('add', 'a'); git('commit', '-m', 'feature commit', '-m', 'full commit body')
            feature = git('rev-parse', 'HEAD')
            git('checkout', 'master'); git('merge', '--no-ff', 'feature', '-m', 'Merge PR')
            merge = git('rev-parse', 'HEAD')
            git('commit', '--allow-empty', '-m', 'squashed feature', '-m', 'squash body')
            squash = git('rev-parse', 'HEAD')
            git('commit', '--allow-empty', '-m', 'rebased direct', '-m', 'rebase full body')
            direct = git('rev-parse', 'HEAD')
            def prs(repo, sha):
                if sha in (feature, merge):
                    return [pull(1, merge=merge)]
                if sha == squash:
                    return [pull(2, merge=squash)]
                return []
            with patch.object(notes, 'github_pulls', side_effect=prs):
                rows = notes.collect_sources('v1.0.0..HEAD', REPO, git)
            self.assertEqual(['pr:1', 'pr:2', 'commit:' + direct], [row['id'] for row in rows])
            self.assertEqual([feature, merge], rows[0]['commits'])
            self.assertEqual('rebase full body', rows[2]['body'])


class RenderTest(unittest.TestCase):
    def setUp(self):
        self.sources = [{'id': 'pr:1', 'url': 'https://github.com/owner/repo/pull/1'}]
        self.entry = {'category': 'Fixes', 'sources': ['pr:1'], 'en': 'Fix stale requests.', 'zh': '修复旧请求覆盖新结果的问题。'}

    def result(self, entry=None, **kwargs):
        return notes.render_notes(json.dumps({'entries': [entry or self.entry]}, ensure_ascii=False), self.sources, **kwargs)

    def test_renders_bilingual_notes_and_program_owned_sources(self):
        result = self.result()
        self.assertIn('### Fixes\n\n- Fix stale requests.', result)
        self.assertIn('<!-- lang:zh -->\n\n### 问题修复', result)
        self.assertEqual(2, result.count('[#1](https://github.com/owner/repo/pull/1)'))

    def test_missing_unknown_and_uncovered_sources_fail(self):
        for ids in [[], ['pr:2']]:
            with self.subTest(ids=ids), self.assertRaises(ValueError):
                self.result({**self.entry, 'sources': ids})
        with self.assertRaises(ValueError):
            notes.render_notes(json.dumps({'entries': [self.entry]}), self.sources + [{'id': 'pr:2', 'url': 'url'}])

    def test_empty_one_language_wrong_type_and_unknown_category_fail(self):
        for changed in [{'zh': ''}, {'en': ' '}, {'en': []}, {'sources': 'pr:1'}, {'category': 'Unknown'}, {'zh': 'only English'}]:
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                self.result({**self.entry, **changed})

    def test_invalid_json_fenced_json_empty_and_wrong_shape_fail(self):
        for content in ['bad', '```json\n{}\n```', '{}', '[]', '{"entries":[]}', '{"entries":[1]}']:
            with self.subTest(content=content), self.assertRaises(ValueError):
                notes.render_notes(content, self.sources)

    def test_injected_markdown_links_separator_and_html_are_rejected(self):
        for text in ['text\n### New heading', '<!-- lang:zh -->', '[link](https://evil.test)', '<img src=x>', '![image](x)']:
            with self.subTest(text=text), self.assertRaises(ValueError):
                self.result({**self.entry, 'en': text})

    def test_exact_runtime_constraint_and_removal_rendered_in_both_languages(self):
        changes = {'php': {'before': '^8.4', 'after': '~8.4.27 || ^8.5.12'}, 'ext-old': {'before': '*', 'after': None}}
        result = self.result(requirements=changes)
        self.assertEqual(2, result.count('`~8.4.27 || ^8.5.12`'))
        self.assertIn('no longer required', result)
        self.assertIn('不再要求', result)

    def test_source_can_be_combined_without_omission(self):
        entry = {**self.entry, 'sources': ['pr:1', 'commit:' + 'a' * 40]}
        self.sources.append({'id': 'commit:' + 'a' * 40, 'url': 'https://github.com/owner/repo/commit/' + 'a' * 40})
        self.assertIn('[aaaaaaa]', self.result(entry))


class WorkflowTest(unittest.TestCase):
    def test_notes_waits_for_checks_and_allows_dispatch_preview(self):
        text = Path(__file__).parents[1].joinpath('workflows/release.yml').read_text()
        notes = text.split('  notes:\n', 1)[1].split('  publish:\n', 1)[0]
        self.assertIn('needs: [validate, checks]', notes)
        expression = "!cancelled() && needs.validate.result == 'success' && (github.event_name == 'workflow_dispatch' || needs.checks.result == 'success')"
        self.assertIn('${{ ' + expression + ' }}', notes)
        self.assertIn('pull-requests: read', notes)
        self.assertIn('GH_TOKEN: ${{ github.token }}', notes)
        self.assertNotIn('contents: write', notes)
        cases = [
            (False, 'success', 'success', 'push', True),
            (False, 'success', 'failure', 'push', False),
            (False, 'success', 'skipped', 'push', False),
            (False, 'success', 'cancelled', 'push', False),
            (False, 'failure', 'success', 'push', False),
            (False, 'success', 'skipped', 'workflow_dispatch', True),
            (False, 'failure', 'skipped', 'workflow_dispatch', False),
            (False, 'skipped', 'skipped', 'workflow_dispatch', False),
            (True, 'success', 'success', 'push', False),
            (True, 'success', 'skipped', 'workflow_dispatch', False),
        ]
        for cancelled, validation, checks, event, expected in cases:
            condition = expression.replace('!cancelled()', str(not cancelled)).replace('needs.validate.result', repr(validation)).replace('needs.checks.result', repr(checks)).replace('github.event_name', repr(event)).replace('&&', 'and').replace('||', 'or')
            self.assertEqual(expected, eval(condition, {'__builtins__': {}}, {}))
        publish = text.split('  publish:\n', 1)[1]
        self.assertIn('needs: [validate, checks, notes]', publish)
        self.assertIn("if: github.event_name == 'push'", publish)


class TagValidationTest(unittest.TestCase):
    def test_tag_must_resolve_to_commit_on_master(self):
        with patch.object(notes, 'git', return_value='a' * 40) as git, patch.object(notes.subprocess, 'run') as run:
            self.assertEqual('a' * 40, notes.validate('v1.2.3'))
        git.assert_called_once_with('rev-parse', '--verify', 'refs/tags/v1.2.3^{commit}')
        run.assert_called_once_with(['git', 'merge-base', '--is-ancestor', 'a' * 40, 'refs/remotes/origin/master'], check=True)

    def test_non_master_history_is_rejected(self):
        with patch.object(notes, 'git', return_value='a' * 40), patch.object(notes.subprocess, 'run', side_effect=notes.subprocess.CalledProcessError(1, 'git')):
            with self.assertRaises(notes.subprocess.CalledProcessError):
                notes.validate('v1.2.3')

    def test_changed_tag_is_rejected(self):
        with patch.object(notes, 'git', return_value='a' * 40), patch.object(notes.subprocess, 'run'), patch.dict(notes.os.environ, {'RELEASE_EXPECTED_SHA': 'b' * 40}):
            with self.assertRaises(ValueError):
                notes.validate('v1.2.3')


if __name__ == '__main__':
    unittest.main()
