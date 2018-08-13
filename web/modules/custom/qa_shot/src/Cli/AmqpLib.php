<?php

namespace Drupal\qa_shot\Cli;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class AmqpLib.
 *
 * @package Drupal\qa_shot\Cli
 */
class AmqpLib {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Connection to RabbitMQ.
   *
   * @var \PhpAmqpLib\Connection\AMQPStreamConnection
   */
  protected $rabbitConnection;

  /**
   * Channels to RabbitMQ.
   *
   * @var \PhpAmqpLib\Channel\AMQPChannel[]
   */
  protected $channels;

  /**
   * An array of loaded channel configs.
   *
   * @var array
   */
  protected $channelConfigs;

  /**
   * An array of consumed messages.
   *
   * @var array
   */
  protected $consumedMessages;

  /**
   * AmqpLib constructor.
   */
  public function __construct() {
    $this->messenger = \Drupal::messenger();
    $rabbitConfig = \Drupal::configFactory()->get('rabbitmq.settings');
    $connectionInfo = $rabbitConfig->get('connection');
    $this->channelConfigs = $rabbitConfig->get('channels');

    $this->rabbitConnection = new AMQPStreamConnection(
      $connectionInfo['host'],
      $connectionInfo['port'],
      $connectionInfo['user'],
      $connectionInfo['pass'],
      $connectionInfo['vhost']
    );

    foreach ($this->channelConfigs as $key => $config) {
      $this->createChannel($key, $config);
    }
  }

  /**
   * Create a single channel.
   *
   * @param string $key
   *   The key to be used.
   * @param array $config
   *   The config of the channel.
   */
  protected function createChannel(string $key, array $config) {
    $channel = $this->rabbitConnection->channel();
    $channel->queue_declare($config['queue'], FALSE, FALSE, FALSE, TRUE);
    $channel->exchange_declare($config['exchange'], 'direct', FALSE, FALSE, TRUE);
    $channel->queue_bind($config['queue'], $config['exchange']);
    $this->channels[$key] = $channel;
  }

  /**
   * Consume every message from each channel.
   */
  public function consumeAll() {
    foreach ($this->channels as $key => $channel) {
      $consumerTag = $this->channelConfigs[$key]['consumer_tag'];

      $channel->basic_consume(
        $this->channelConfigs[$key]['consumer_tag']['queue'],
        $consumerTag,
        FALSE,
        FALSE,
        FALSE,
        FALSE,
        [$this, 'consumeMessage']
      );

      while (\count($channel->callbacks)) {
        $channel->wait();
      }

      $channel->close();
    }

    $this->rabbitConnection->close();
  }

  /**
   * Callback for channel message consumers.
   *
   * @param \PhpAmqpLib\Message\AMQPMessage $message
   *   The current message.
   *
   * @see: https://github.com/php-amqplib/php-amqplib/blob/master/demo/amqp_consumer.php
   */
  public function consumeMessage(AMQPMessage $message) {
    /** @var \PhpAmqpLib\Channel\AMQPChannel $channel */
    $channel = $message->delivery_info['channel'];
    $channel->basic_ack($message->delivery_info['delivery_tag']);

    $this->consumedMessages[] = [
      'queue' => '',
      'message' => $message->getBody(),
    ];
  }

}
