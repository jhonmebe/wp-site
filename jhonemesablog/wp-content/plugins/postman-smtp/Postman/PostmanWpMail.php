<?php
if (! class_exists ( "PostmanWpMail" )) {
	
	require_once 'Postman-Email-Log/PostmanEmailLogService.php';
	require_once 'Postman-Email-Log/PostmanEmailLogController.php';
	require_once 'Postman-Mail/PostmanMessage.php';
	require_once 'Postman-Auth/PostmanAuthenticationManagerFactory.php';
	require_once 'Postman-Mail/PostmanMailEngine.php';
	require_once 'PostmanStats.php';
	
	/**
	 * Moved this code into a class so it could be used by both wp_mail() and PostmanSendTestEmailController
	 *
	 * @author jasonhendriks
	 *        
	 */
	class PostmanWpMail {
		private $exception;
		private $transcript;
		private $totalTime;
		private $logger;
		
		/**
		 * constructor
		 */
		public function __construct() {
			$this->logger = new PostmanLogger ( get_class ( $this ) );
		}
		
		/**
		 * This methods creates an instance of PostmanSmtpEngine and sends an email.
		 * Exceptions are held for later inspection. An instance of PostmanStats updates the success/fail tally.
		 *
		 * @param PostmanOptions $options        	
		 * @param PostmanOAuthToken $authorizationToken        	
		 * @param unknown $to        	
		 * @param unknown $subject        	
		 * @param unknown $body        	
		 * @param unknown $headers        	
		 * @param unknown $attachments        	
		 * @return boolean
		 */
		public function send($to, $subject, $message, $headers = '', $attachments = array()) {
			// start the clock
			$startTime = microtime ( true ) * 1000;
			
			$this->logger->trace ( 'wp_mail parameters before applying WordPress wp_mail filter:' );
			$this->traceParameters ( $to, $subject, $message, $headers, $attachments );
			
			/**
			 * Filter the wp_mail() arguments.
			 *
			 * @since 1.5.4
			 *       
			 * @param array $args
			 *        	A compacted array of wp_mail() arguments, including the "to" email,
			 *        	subject, message, headers, and attachments values.
			 */
			$atts = apply_filters ( 'wp_mail', compact ( 'to', 'subject', 'message', 'headers', 'attachments' ) );
			$originalTo = $to;
			if (isset ( $atts ['to'] )) {
				$to = $atts ['to'];
			}
			
			$originalSubject = $subject;
			if (isset ( $atts ['subject'] )) {
				$subject = $atts ['subject'];
			}
			
			$originalMessage = $message;
			if (isset ( $atts ['message'] )) {
				$message = $atts ['message'];
			}
			
			$originalHeaders = $headers;
			if (isset ( $atts ['headers'] )) {
				$headers = $atts ['headers'];
			}
			
			if (isset ( $atts ['attachments'] )) {
				$attachments = $atts ['attachments'];
			}
			
			if (! is_array ( $attachments )) {
				$attachments = explode ( "\n", str_replace ( "\r\n", "\n", $attachments ) );
			}
			
			$this->logger->trace ( 'wp_mail parameters after applying WordPress wp_mail filter:' );
			$this->traceParameters ( $to, $subject, $message, $headers, $attachments );
			
			// register the response hook
			add_filter ( 'postman_wp_mail_result', array (
					$this,
					'postman_wp_mail_result' 
			) );
			
			// get the Options and AuthToken
			$options = PostmanOptions::getInstance ();
			$authorizationToken = PostmanOAuthToken::getInstance ();
			
			// get the transport and create the transportConfig and engine
			$transport = PostmanTransportRegistry::getInstance ()->getCurrentTransport ();
			$transportConfiguration = $transport->createPostmanMailAuthenticator ( $options, $authorizationToken );
			$engine = new PostmanMailEngine ( $transport, $transportConfiguration );
			
			// is this a test run?
			$testMode = apply_filters ( 'postman_test_email', false );
			$this->logger->debug ( 'testMode=' . $testMode );
			
			// create the message
			$messageBuilder = $this->createMessage ( $options, $to, $subject, $message, $headers, $attachments, $transportConfiguration );
			
			try {
				
				// send the message
				if ($options->getRunMode () == PostmanOptions::RUN_MODE_PRODUCTION) {
					if ($options->isAuthTypeOAuth2 ()) {
						PostmanUtils::lock ();
						// may throw an exception attempting to contact the OAuth2 provider
						$this->ensureAuthtokenIsUpdated ( $transport, $options, $authorizationToken );
					}
					
					$this->logger->debug ( 'Sending mail' );
					// may throw an exception attempting to contact the SMTP server
					$engine->send ( $messageBuilder, $options->getHostname () );
					
					// increment the success counter, unless we are just tesitng
					if (! $testMode) {
						PostmanStats::getInstance ()->incrementSuccessfulDelivery ();
					}
				}
				if ($options->getRunMode () == PostmanOptions::RUN_MODE_PRODUCTION || $options->getRunMode () == PostmanOptions::RUN_MODE_LOG_ONLY) {
					// log the successful delivery
					PostmanEmailLogService::getInstance ()->writeSuccessLog ( $messageBuilder, $engine->getTranscript (), $transport );
				}
				
				// clean up
				$this->postSend ( $engine, $startTime, $options );
				
				// return successful
				return true;
			} catch ( Exception $e ) {
				// save the error for later
				$this->exception = $e;
				
				// write the error to the PHP log
				$this->logger->error ( get_class ( $e ) . ' code=' . $e->getCode () . ' message=' . trim ( $e->getMessage () ) );
				
				// increment the failure counter, unless we are just tesitng
				if (! $testMode && $options->getRunMode () == PostmanOptions::RUN_MODE_PRODUCTION) {
					PostmanStats::getInstance ()->incrementFailedDelivery ();
				}
				if ($options->getRunMode () == PostmanOptions::RUN_MODE_PRODUCTION || $options->getRunMode () == PostmanOptions::RUN_MODE_LOG_ONLY) {
					// log the failed delivery
					PostmanEmailLogService::getInstance ()->writeFailureLog ( $messageBuilder, $engine->getTranscript (), $transport, $e->getMessage (), $originalTo, $originalSubject, $originalMessage, $originalHeaders );
				}
				
				// clean up
				$this->postSend ( $engine, $startTime, $options );
				
				// return failure
				return false;
			}
		}
		
		/**
		 * Clean up after sending the mail
		 *
		 * @param PostmanMailEngine $engine        	
		 * @param unknown $startTime        	
		 */
		private function postSend(PostmanMailEngine $engine, $startTime, PostmanOptions $options) {
			// save the transcript
			$this->transcript = $engine->getTranscript ();
			
			// delete the semaphore
			if ($options->isAuthTypeOAuth2 ()) {
				PostmanUtils::unlock ();
			}
			
			// stop the clock
			$endTime = microtime ( true ) * 1000;
			$this->totalTime = $endTime - $startTime;
		}
		
		/**
		 *
		 * @return multitype:Exception NULL
		 */
		function postman_wp_mail_result() {
			$result = array (
					'time' => $this->totalTime,
					'exception' => $this->exception,
					'transcript' => $this->transcript 
			);
			return $result;
		}
		
		/**
		 */
		private function ensureAuthtokenIsUpdated(PostmanTransport $transport, PostmanOptions $options, PostmanOAuthToken $authorizationToken) {
			// ensure the token is up-to-date
			$this->logger->debug ( 'Ensuring Access Token is up-to-date' );
			// interact with the Authentication Manager
			$wpMailAuthManager = PostmanAuthenticationManagerFactory::getInstance ()->createAuthenticationManager ( $transport, $options, $authorizationToken );
			if ($wpMailAuthManager->isAccessTokenExpired ()) {
				$this->logger->debug ( 'Access Token has expired, attempting refresh' );
				$wpMailAuthManager->refreshToken ();
				$authorizationToken->save ();
			}
		}
		
		/**
		 * Aggregates all the content into a Message to be sent to the MailEngine
		 *
		 * @param unknown $options        	
		 * @param unknown $to        	
		 * @param unknown $subject        	
		 * @param unknown $body        	
		 * @param unknown $headers        	
		 * @param unknown $attachments        	
		 */
		private function createMessage(PostmanOptions $options, $to, $subject, $body, $headers, $attachments, PostmanMailTransportConfiguration $transportation) {
			$message = new PostmanMessage ();
			$message->addHeaders ( $headers );
			$message->addHeaders ( $options->getAdditionalHeaders () );
			$message->setBody ( $body );
			$message->setSubject ( $subject );
			$message->addTo ( $to );
			$message->addTo ( $options->getForcedToRecipients () );
			$message->addCc ( $options->getForcedCcRecipients () );
			$message->addBcc ( $options->getForcedBccRecipients () );
			$message->setAttachments ( $attachments );
			$message->setSender ( $options->getSenderEmail (), $options->getSenderName () );
			$message->setPreventSenderEmailOverride ( $options->isSenderEmailOverridePrevented () );
			$message->setPreventSenderNameOverride ( $options->isSenderNameOverridePrevented () );
			$message->setPostmanSignatureEnabled ( ! $options->isStealthModeEnabled () );
			
			// set the reply-to address if it hasn't been set already in the user's headers
			$optionsReplyTo = $options->getReplyTo ();
			$messageReplyTo = $message->getReplyTo ();
			if (! empty ( $optionsReplyTo ) && empty ( $messageReplyTo )) {
				$message->setReplyTo ( $optionsReplyTo );
			}
			return $message;
		}
		
		/**
		 * Trace the parameters to aid in debugging
		 *
		 * @param unknown $to        	
		 * @param unknown $subject        	
		 * @param unknown $body        	
		 * @param unknown $headers        	
		 * @param unknown $attachments        	
		 */
		private function traceParameters($to, $subject, $message, $headers, $attachments) {
			$this->logger->trace ( 'to:' );
			$this->logger->trace ( $to );
			$this->logger->trace ( 'subject:' );
			$this->logger->trace ( $subject );
			$this->logger->trace ( 'headers:' );
			$this->logger->trace ( $headers );
			$this->logger->trace ( 'attachments:' );
			$this->logger->trace ( $attachments );
			$this->logger->trace ( 'message:' );
			$this->logger->trace ( $message );
		}
		
		/**
		 *
		 * @return Exception
		 */
		public function getException() {
			return $this->exception;
		}
		public function getTranscript() {
			return $this->transcript;
		}
	}
}