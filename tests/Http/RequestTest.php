<?php

declare(strict_types=1);

namespace Luxid\Tests\Http;

use Luxid\Http\Request;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behavioural tests for request parsing.
 *
 * @package Luxid\Tests\Http
 */
final class RequestTest extends TestCase
{
    #[Test]
    public function it_returns_the_requested_query_parameter_not_the_last_one(): void
    {
        // Regression: the lookup key was shadowed by the loop variable that
        // populated the cache, so the first call returned the final parameter.
        $this->request('GET', '/todos', ['status' => 'pending', 'page' => '2']);

        $request = new Request();

        $this->assertSame('pending', $request->query('status'));
        $this->assertSame('2', $request->query('page'));
    }

    #[Test]
    public function it_returns_the_default_for_a_missing_query_parameter(): void
    {
        $this->request('GET', '/todos', ['status' => 'pending']);

        $this->assertSame('created_at', (new Request())->query('sort', 'created_at'));
    }

    #[Test]
    public function it_returns_every_query_parameter_when_no_key_is_given(): void
    {
        $this->request('GET', '/todos', ['a' => '1', 'b' => '2']);

        $this->assertSame(['a' => '1', 'b' => '2'], (new Request())->query());
    }

    #[Test]
    public function it_strips_the_query_string_from_the_path(): void
    {
        $_SERVER['REQUEST_URI'] = '/todos?status=pending';

        $this->assertSame('/todos', (new Request())->getPath());
    }

    #[Test]
    public function it_honours_the_method_override_field(): void
    {
        // Regression: the guard checked $_POST['method'] while the value was read
        // from $_POST['_method'], so form based overrides never applied.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_method' => 'PUT'];

        $this->assertSame('put', (new Request())->method());
    }

    #[Test]
    public function it_honours_the_method_override_header(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';

        $this->assertSame('delete', (new Request())->method());
    }

    #[Test]
    public function it_ignores_an_unroutable_method_override(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_method' => 'TRACE'];

        $this->assertSame('post', (new Request())->method());
    }

    #[Test]
    public function it_ignores_a_method_override_on_a_get_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';

        $this->assertSame('get', (new Request())->method());
    }

    #[Test]
    public function it_reads_an_explicitly_set_json_body(): void
    {
        $request = new Request();
        $request->setMethod('post');
        $request->setBody('{"title":"Write tests","done":false}');

        $this->assertSame('Write tests', $request->get('title'));
        $this->assertFalse($request->get('done'));
    }

    #[Test]
    public function it_sanitizes_query_values(): void
    {
        $this->request('GET', '/search', ['q' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', (string) (new Request())->query('q'));
    }

    #[Test]
    public function it_sanitizes_nested_query_values(): void
    {
        $this->request('GET', '/search', ['filter' => ['name' => '<b>x</b>']]);

        $query = (new Request())->query('filter');

        $this->assertIsArray($query);
        $this->assertStringNotContainsString('<b>', $query['name']);
    }

    #[Test]
    public function it_returns_only_the_requested_keys(): void
    {
        $request = new Request();
        $request->setMethod('post');
        $request->setBody('{"title":"a","status":"b","extra":"c"}');

        $this->assertSame(['title' => 'a', 'status' => 'b'], $request->only(['title', 'status']));
    }

    #[Test]
    public function it_returns_everything_except_the_named_keys(): void
    {
        $request = new Request();
        $request->setMethod('post');
        $request->setBody('{"title":"a","password":"b"}');

        $this->assertSame(['title' => 'a'], $request->except(['password']));
    }

    #[Test]
    public function it_reports_whether_a_key_is_filled(): void
    {
        $request = new Request();
        $request->setMethod('post');
        $request->setBody('{"title":"a","note":""}');

        $this->assertTrue($request->filled('title'));
        $this->assertFalse($request->filled('note'));
        $this->assertFalse($request->filled('missing'));
    }

    #[Test]
    public function it_reads_headers_case_insensitively(): void
    {
        $request = new Request();
        $request->setHeader('Content-Type', 'application/json');

        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertTrue($request->isJson());
    }

    #[Test]
    public function it_detects_an_ajax_request(): void
    {
        $request = new Request();
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');

        $this->assertTrue($request->isAjax());
    }

    #[Test]
    public function it_returns_an_empty_input_array_for_get_requests(): void
    {
        $this->request('GET', '/todos', ['status' => 'pending']);

        $this->assertSame([], (new Request())->input());
    }
}
