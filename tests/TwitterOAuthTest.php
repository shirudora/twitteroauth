<?php
/**
 * WARNING: Running these tests will post and delete through the actual Twitter account.
 */
namespace Abraham\TwitterOAuth\Test;

require __DIR__ . '/../vendor/autoload.php';
use Abraham\TwitterOAuth\TwitterOAuth;

define('CONSUMER_KEY', getenv('TEST_CONSUMER_KEY'));
define('CONSUMER_SECRET', getenv('TEST_CONSUMER_SECRET'));
define('ACCESS_TOKEN', getenv('TEST_ACCESS_TOKEN'));
define('ACCESS_TOKEN_SECRET', getenv('TEST_ACCESS_TOKEN_SECRET'));
define('OAUTH_CALLBACK', getenv('TEST_OAUTH_CALLBACK'));
define('PROXY', getenv('TEST_CURLOPT_PROXY'));
define('PROXYUSERPWD', getenv('TEST_CURLOPT_PROXYUSERPWD'));
define('PROXYPORT', getenv('TEST_CURLOPT_PROXYPORT'));

class TwitterOAuthTest extends \PHPUnit_Framework_TestCase
{

    protected $twitter;

    protected function setUp()
    {
        $this->twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
    }

    public function testBuildClient()
    {
        $this->assertObjectHasAttribute('consumer', $this->twitter);
        $this->assertObjectHasAttribute('token', $this->twitter);
    }

    public function testOauthRequestToken()
    {
        $twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET);
        $result = $twitter->oauth('oauth/request_token', array('oauth_callback' => OAUTH_CALLBACK));
        $this->assertEquals(200, $twitter->lastHttpCode());
        $this->assertArrayHasKey('oauth_token', $result);
        $this->assertArrayHasKey('oauth_token_secret', $result);
        $this->assertArrayHasKey('oauth_callback_confirmed', $result);
        $this->assertEquals('true', $result['oauth_callback_confirmed']);
        return $result;
    }

    /**
     * @expectedException Abraham\TwitterOAuth\TwitterOAuthException
     * @expectedExceptionMessage Failed to validate oauth signature and token
     */
    public function testOauthRequestTokenException()
    {
        $twitter = new TwitterOAuth('CONSUMER_KEY', 'CONSUMER_SECRET');
        $result = $twitter->oauth('oauth/request_token', array('oauth_callback' => OAUTH_CALLBACK));
        return $result;
    }

    /**
     * @expectedException Abraham\TwitterOAuth\TwitterOAuthException
     * @expectedExceptionMessage Invalid request token
     * @depends testOauthRequestToken
     */
    public function testOauthAccessTokenTokenException($request_token)
    {
        // Can't test this without a browser logging into Twitter so check for the correct error instead.
        $twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $request_token['oauth_token'], $request_token['oauth_token_secret']);
        $result = $twitter->oauth("oauth/access_token", array("oauth_verifier" => "fake_oauth_verifier"));
    }

    public function testUrl()
    {
        $url = $this->twitter->url('oauth/authorize', array('foo' => 'bar', 'baz' => 'qux'));
        $this->assertEquals('https://api.twitter.com/oauth/authorize?foo=bar&baz=qux', $url);
    }

    public function testGetAccountVerifyCredentials()
    {
        $result = $this->twitter->get('account/verify_credentials');
        $this->assertEquals(200, $this->twitter->lastHttpCode());
    }

    public function testSetProxy()
    {
        $this->twitter->setProxy(array(
            'CURLOPT_PROXY' => PROXY,
            'CURLOPT_PROXYUSERPWD' => PROXYUSERPWD,
            'CURLOPT_PROXYPORT' => PROXYPORT,
        ));
        $this->twitter->setConnectionTimeout(25);
        $this->twitter->setTimeout(25);
        $result = $this->twitter->get('account/verify_credentials');
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        $this->assertObjectHasAttribute('id', $result);
    }

    public function testGetStatusesMentionsTimeline()
    {
        $result = $this->twitter->get('statuses/mentions_timeline');
        $this->assertEquals(200, $this->twitter->lastHttpCode());
    }

    public function testGetSearchTweets()
    {
        $result = $this->twitter->get('search/tweets', array('q' => 'twitter'));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        return $result->statuses;
    }

    /**
     * @depends testGetSearchTweets
     */
    public function testGetSearchTweetsWithMaxId($statuses)
    {
        $max_id = array_pop($statuses)->id_str;
        $result = $this->twitter->get('search/tweets', array('q' => 'twitter', 'max_id' => $max_id));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
    }

    public function testPostFavoritesCreate()
    {
        $result = $this->twitter->post('favorites/create', array('id' => '6242973112'));
        if ($this->twitter->lastHttpCode() == 403) {
            // Status already favorited
            $this->assertEquals(139, $result->errors[0]->code);
        } else {
            $this->assertEquals(200, $this->twitter->lastHttpCode());
        }
    }

    /**
     * @depends testPostFavoritesCreate
     */
    public function testPostFavoritesDestroy()
    {
        $result = $this->twitter->post('favorites/destroy', array('id' => '6242973112'));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
    }

    public function testPostStatusesUpdate()
    {
        $result = $this->twitter->post('statuses/update', array('status' => 'Hello World ' . time()));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        return $result;
    }

    public function testPostStatusesUpdateWithMedia()
    {
        // Image source https://www.flickr.com/photos/titrans/8548825587/
        $file_path = __DIR__ . '/kitten.jpg';
        $result = $this->twitter->upload('media/upload', array('media' => $file_path));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        $this->assertObjectHasAttribute('media_id_string', $result);
        $parameters = array('status' => 'Hello World ' . time(), 'media_ids' => $result->media_id_string);
        $result = $this->twitter->post('statuses/update', $parameters);
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        if ($this->twitter->lastHttpCode() == 200) {
            $result = $this->twitter->post('statuses/destroy/' . $result->id_str);
        }
        return $result;
    }

    public function testPostStatusesUpdateUtf8()
    {
        $result = $this->twitter->post('statuses/update', array('status' => 'xこんにちは世界 ' . time()));
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        if ($this->twitter->lastHttpCode() == 200) {
            $result = $this->twitter->post('statuses/destroy/' . $result->id_str);
        }
        return $result;
    }

    /**
     * @depends testPostStatusesUpdate
     */
    public function testPostStatusesDestroy($status)
    {
        $result = $this->twitter->post('statuses/destroy/' . $status->id_str);
        $this->assertEquals(200, $this->twitter->lastHttpCode());
    }

    public function testLastResult()
    {
        $result = $this->twitter->get('account/verify_credentials');
        $this->assertEquals('account/verify_credentials', $this->twitter->lastApiPath());
        $this->assertEquals(200, $this->twitter->lastHttpCode());
        $this->assertEquals('GET', $this->twitter->lastHttpMethod());
        $this->assertObjectHasAttribute('id', $this->twitter->lastResponse());
    }

    /**
     * @depends testLastResult
     */
    public function testResetLastResult()
    {
        $this->twitter->resetLastResult();
        $this->assertEquals('', $this->twitter->lastApiPath());
        $this->assertEquals(0, $this->twitter->lastHttpCode());
        $this->assertEquals('', $this->twitter->lastHttpMethod());
        $this->assertEquals(array(), $this->twitter->lastResponse());
    }
}
