import importlib.util
import http.client
import io
import json
import socket
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

    def test_timeout_recovers_on_third_attempt(self):
        response = io.BytesIO(json.dumps({'choices': [{'message': {'content': 'Recovered'}}]}).encode())
        with patch.object(notes.HTTP, 'open', side_effect=[TimeoutError(), TimeoutError(), response]) as call:
            self.assertEqual('Recovered', notes.complete('https://example.test/v1', 'key', 'model', '', ''))
        self.assertEqual(3, call.call_count)

    def test_transient_failures_stop_after_three_attempts_without_logs(self):
        for failure in [TimeoutError('private endpoint'), urllib.error.URLError(socket.gaierror('private endpoint')),
                        http.client.IncompleteRead(b'private response')]:
            with self.subTest(failure=type(failure).__name__), patch.object(notes.HTTP, 'open', side_effect=failure) as call, patch('sys.stderr', new_callable=io.StringIO) as log:
                with self.assertRaises(notes.GenerationError):
                    notes.complete('https://example.test/v1', 'secret', 'model', '', '')
                self.assertEqual(3, call.call_count)
                self.assertEqual('', log.getvalue())

    def test_public_warning_never_prints_error_details(self):
        with patch.object(notes, 'complete', side_effect=notes.GenerationError('private endpoint secret')), patch('sys.stderr', new_callable=io.StringIO) as log:
            notes.generate(['fix: example'], ('url', 'key', 'model'))
        self.assertNotIn('private endpoint secret', log.getvalue())

    def test_server_error_recovers_on_retry(self):
        failure = urllib.error.HTTPError('url', 503, 'unavailable', {}, io.BytesIO(b'private response'))
        response = io.BytesIO(json.dumps({'choices': [{'message': {'content': 'Recovered'}}]}).encode())
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
        response = io.BytesIO(json.dumps({'choices': [{'message': {'content': ' Notes '}}]}).encode())
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

    def test_both_generation_calls_request_high_and_join_languages(self):
        with patch.object(notes, 'complete', side_effect=['English', '中文']) as call:
            result = notes.generate(['fix: repair login'], ('https://example.test/v1', 'key', 'model'))
        self.assertEqual('English\n\n<!-- lang:zh -->\n\n中文\n', result)
        self.assertEqual(2, call.call_count)
        self.assertIn('English', call.call_args.args[-1])

    def test_english_prompt_preserves_engineering_changes(self):
        context = {'commits': [{'subject': 'test: require complete coverage'}]}
        with patch.object(notes, 'complete', side_effect=['English', '中文']) as call:
            notes.generate(context, ('https://example.test/v1', 'key', 'model'))
        prompt = call.call_args_list[0].args[-2]
        self.assertIn('do not omit those as maintenance', prompt)
        self.assertIn('diagnostic-only', prompt)
        self.assertIn('file-format change', prompt)

    def test_generation_sends_commit_files_and_version_highlights(self):
        context = {
            'commits': [{
                'subject': 'fix(security): require patched runtimes',
                'body': 'Raise minimum versions.',
                'changed_paths': ['composer.json'],
            }],
            'changed_files': [{'path': 'composer.json', 'additions': 1, 'deletions': 1}],
            'version_highlights': {'changed_requirements': {'php': {'before': '^8.4', 'after': '~8.4.27'}}},
        }
        with patch.object(notes, 'complete', side_effect=['English', '中文']) as call:
            notes.generate(context, ('https://example.test/v1', 'key', 'model'))
        source = json.loads(call.call_args_list[0].args[-1])
        self.assertEqual('Raise minimum versions.', source['commits'][0]['body'])
        self.assertEqual(['composer.json'], source['commits'][0]['changed_paths'])
        self.assertEqual('~8.4.27', source['version_highlights']['changed_requirements']['php']['after'])

    def test_generation_failure_falls_back_without_printing_exception(self):
        with patch.object(notes, 'complete', side_effect=ValueError('secret endpoint')), patch('sys.stderr', new_callable=io.StringIO) as error:
            result = notes.generate(['fix: repair login'], ('url', 'key', 'model'))
        self.assertIn('- fix: repair login', result)
        self.assertNotIn('secret endpoint', error.getvalue())
        self.assertIn('Generation failed', error.getvalue())

    def test_translation_failure_keeps_english(self):
        with patch.object(notes, 'complete', side_effect=['English', TimeoutError()]), patch('sys.stderr', new_callable=io.StringIO):
            self.assertEqual('English\n', notes.generate(['fix: a'], ('url', 'key', 'model')))

    def test_incomplete_translation_keeps_english(self):
        with patch.object(notes, 'complete', side_effect=[
            'English',
            notes.GenerationError('incomplete_response'),
        ]), patch('sys.stderr', new_callable=io.StringIO) as error:
            self.assertEqual('English\n', notes.generate(['fix: a'], ('url', 'key', 'model')))
        self.assertNotIn('incomplete_response', error.getvalue())

    def test_missing_configuration_falls_back(self):
        with patch.object(notes, 'complete') as call, patch('sys.stderr', new_callable=io.StringIO):
            self.assertIn('- fix: a', notes.generate(['fix: a'], ('', '', '')))
        call.assert_not_called()

    def test_empty_or_malformed_responses_are_errors(self):
        for payload in [{}, {'choices': [{'message': {'content': ' '}}]}, {'choices': [{'message': {'content': ['bad']}}]}]:
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

    def test_output_cannot_echo_configuration_or_inject_language_separator(self):
        for output in ['secret-key', 'https://example.test/v1', 'model-id', '<!-- lang:zh -->']:
            with patch.object(notes, 'complete', return_value=output), patch('sys.stderr', new_callable=io.StringIO):
                result = notes.generate(['fix: a'], ('https://example.test/v1', 'secret-key', 'model-id'))
            self.assertIn('- fix: a', result)

    def test_oversized_context_falls_back_before_request(self):
        with patch.object(notes, 'complete', return_value='Done') as call, patch('sys.stderr', new_callable=io.StringIO) as error:
            result = notes.generate(['a' * 5000] * 1000, ('url', 'key', 'model'))
        self.assertIn('- ' + 'a' * 500, result)
        self.assertNotIn('context_too_large', error.getvalue())
        call.assert_not_called()

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
                notes.workflow_highlights('a' * 40, ['.github/workflows/tests.yml']),
            )



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
