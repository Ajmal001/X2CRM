<?php

/*****************************************************************************************
 * X2CRM Open Source Edition is a customer relationship management program developed by
 * X2Engine, Inc. Copyright (C) 2011-2013 X2Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 66752, Scotts Valley,
 * California 95067, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2Engine".
 *****************************************************************************************/

Yii::import('application.modules.docs.models.*');
Yii::import('application.modules.actions.models.*');
Yii::import('application.modules.contacts.models.*');
Yii::import('application.modules.quotes.models.*');

/**
 * InlineEmail class. InlineEmail is the data structure for taking in and
 * processing data for outbound email.
 *
 * It is used by the InlineEmailForm widget and site/inlineEmail, and is
 * designed around this principle: that the email is being sent in some context
 * that is dictated by a "target model". Special cases for behavior of the class
 * have been built this way, i.e. when the target model is a {@link Quote}, the
 * insertable attributes should include those of both associated contact and
 * account as well as the quote, and when the email is sent, the action history
 * record that gets created should appropriately describe the event happened,
 * i.e. by saying that "Quote #X was issued by email" rather than merely "user X
 * has sent contact Y an email."
 *
 * The following describes the scenarios of this model:
 * - "custom" is used when a modified email has been submitted for processing or
 * 		sending
 * - "template" is used when the form has been submitted to re-create the email
 * 		based on a template.
 * - Blank/empty string is for when there's a new and blank email (i.e. initial
 * 		rendering of the inline email widget {@link InlineEmailForm})
 *
 * @property string $actionHeader (read-only) A mock-up of the email's header
 * 	fields to be inserted into the email actions' bodies, for display purposes.
 * @property Credentials $credentials (read-only) The credentials record, if applicable.
 * @property array $from The sender of the email.
 * @property PHPMailer $mailer PHPMailer instance
 * @property array $insertableAttributes (read-only) Attributes for the inline
 * 	email editor that can be inserted into the message.
 * @property array $recipientContacts (read-only) an array of contact records
 * 	identified by recipient email address.
 * @property array $recipients (read-only) an array of all recipients of the email.
 * @property string $signature Signature of the user sending the email, if any
 * @property X2Model $targetModel The model associated with this email, i.e.
 * 	Contacts or Quote
 * @property Docs $templateModel (read-only) template, if any, to use.
 * @property string $trackingImage (read-only) Markup for the tracking image to
 * 	be placed in the email
 * @property string $uniqueId A unique ID used for the tracking record and
 * 	tracking image URL
 * @property Profile $userProfile Profile, i.e. for email sender and signature
 * @package X2CRM.models
 */
class InlineEmail extends CFormModel {
    // Enclosure comments:

    const SIGNATURETAG = 'Signature'; // for signature
    const TRACKTAG = 'OpenedEmail'; // for the tracking image
    const AHTAG = 'ActionHeader'; // for the inline action header
    const UIDREGEX = '/uid.([0-9a-f]{32})/';

    /**
     * @var string Email address of the addressees
     */
    public $to;

    /**
     * @var string CC email address(es), if applicable
     */
    public $cc;

	/**
	 * ID of the credentials record to use for SMTP authentication
	 * @var integer
	 */
	public $credId = null;

    /**
     * @var string BCC email address(es), if applicable
     */
    public $bcc;

    /**
     * @var string Email subject
     */
    public $subject;

    /**
     * @var string Email body/content
     */
    public $message;

    /**
     * @var array Sender address
     */
    private $_from;

    /**
     * @var strng Email Send Time
     */
    public $emailSendTime = '';

    /**
     * @var int Email Send Time in unix timestamp format
     */
    public $emailSendTimeParsed = 0;

    /**
     * @var integer Template ID
     */
    public $template = 0;

    /**
     * Stores the name of the model associated with the email i.e. Contacts or Quote.
     * @var string
     */
    public $modelName;

    /**
     * @var integer
     */
    public $modelId;

    /**
     * @var array Status codes
     */
    public $status = array();

    /**
     *
     * @var bool Asssociate emails with the linked Contact (true) or the record itself (false)
     */
    public $contactFlag = true;

    /**
     * @var array
     */
    public $mailingList = array();
    public $attachments = array();
    public $emailBody = '';
    public $preview = false;
    public $stageEmail = false;
    private $_recipientContacts;

    /**
     * Stores value of {@link actionHeader}
     * @var string
     */
    private $_actionHeader;

	/**
	 * Stores the email credentials, if an account has been defined and is used.
	 * @var mixed
	 */
	private $_credentials;

    /**
     * Stores value of {@link insertableAttributes}
     * @var array
     */
    private $_insertableAttributes;

    /**
     * Stores an instance of PHPMailer
     * @var PHPMailer
     */
    private $_mailer;

    /**
     * Stores value of {@link recipients}
     * @var array
     */
    private $_recipients;

    /**
     * Stores value of {@link signature}
     * @var string
     */
    private $_signature;

    /**
     * Stores value of {@link targetModel}
     * @var X2Model
     */
    private $_targetModel;

    /**
     * Stores value of {@link templateModel}
     */
    private $_templateModel;

    /**
     * Stores value of {@link trackingImage}
     * @var string
     */
    private $_trackingImage;

    /**
     * Stores value of {@link uniqueId}
     * @var type
     */
    private $_uniqueId;

    /**
     * Stores value of {@link userProfile}
     * @var Profile
     */
    private $_userProfile;

    /**
     * Declares the validation rules. The rules state that username and password
     * are required, and password needs to be authenticated.
     * @return array
     */
    public function rules(){
        return array(
            array('to, subject', 'required', 'on' => 'custom'),
            // array('modelName,modelId', 'required', 'on' => 'template'),
            array('message', 'required', 'on' => 'custom'),
            array('to,cc,bcc', 'parseMailingList'),
            array('emailSendTime', 'date', 'allowEmpty' => true, 'timestampAttribute' => 'emailSendTimeParsed'),
            array('to, cc, credId, bcc, message, template, modelId, modelName, subject', 'safe'),
        );
    }

    /**
     * Declares attribute labels.
     * @return array
     */
    public function attributeLabels(){
        return array(
            'from' => Yii::t('app', 'From:'),
            'to' => Yii::t('app', 'To:'),
            'cc' => Yii::t('app', 'CC:'),
            'bcc' => Yii::t('app', 'BCC:'),
            'subject' => Yii::t('app', 'Subject:'),
            'message' => Yii::t('app', 'Message:'),
            'template' => Yii::t('app', 'Template:'),
            'modelName' => Yii::t('app', 'Model Name'),
            'modelId' => Yii::t('app', 'Model ID'),
			'credId' => Yii::t('app','Send As:'),
        );
    }

    /**
     * Creates a pattern for finding or inserting content into the email body.
     *
     * @param string $name The name of the pattern to use. There should be a
     * 	constant defined that is the name in upper case followed by "TAG" that
     * 	specifies the name to use in comments that demarcate the inserted content.
     * @param string $inside The content to be inserted between comments.
     * @param bool $re Whether to return the pattern as a regular expression
     * @param string $reFlags PCRE flags to use in the expression, if $re is enabled.
     */
    public static function insertedPattern($name, $inside, $re = 0, $reFlags = ''){
        $tn = constant('self::'.strtoupper($name.'tag'));
        $tag = "<!--Begin$tn-->~inside~<!--End$tn--!>";
        if($re)
            $tag = '/'.preg_quote($tag)."/$reFlags";
        return str_replace('~inside~', $inside, $tag);
    }

    /**
     * Magic getter for {@link actionHeader}
     *
     * Composes an informative header for the action record.
     *
     * @return type
     */
    public function getActionHeader(){
        if(!isset($this->_actionHeader)){

            $recipientContacts = $this->recipientContacts;

            // Add email headers to the top of the action description's body
            // so that the resulting recorded action has all the info of the
            // original email.
            $fromString = $this->from['address'];
            if(!empty($this->from['name']))
                $fromString = '"'.$this->from['name'].'" <'.$fromString.'>';

            $header = CHtml::tag('strong', array(), Yii::t('app', 'Subject: ')).CHtml::encode($this->subject).'<br />';
            $header .= CHtml::tag('strong', array(), Yii::t('app', 'From: ')).CHtml::encode($fromString).'<br />';
            // Put in recipient lists, and if any correspond to contacts, make links
            // to them in place of their names.
            foreach(array('to', 'cc', 'bcc') as $recList){
                if(!empty($this->mailingList[$recList])){
                    $header .= CHtml::tag('strong', array(), ucfirst($recList).': ');
                    foreach($this->mailingList[$recList] as $target){
                        if($recipientContacts[$target[1]] != null){
                            $header .= $recipientContacts[$target[1]]->link;
                        }else{
                            $header .= CHtml::encode("\"{$target[0]}\"");
                        }
                        $header .= CHtml::encode(" <{$target[1]}>,");
                    }
                    $header = rtrim($header, ', ').'<br />';
                }
            }

            // Include special quote information if it's a quote being issued or emailed to a random contact
            if($this->modelName == 'Quote'){
                $header .= '<br /><hr />';
                $header .= CHtml::tag('strong', array(), Yii::t('quotes', $this->targetModel->type == 'invoice' ? 'Invoice' : 'Quote')).':';
                $header .= ' '.$this->targetModel->link.($this->targetModel->status ? ' ('.$this->targetModel->status.'), ' : ' ').Yii::t('app', 'Created').' '.$this->targetModel->renderAttribute('createDate').';';
                $header .= ' '.Yii::t('app', 'Updated').' '.$this->targetModel->renderAttribute('lastUpdated').' by '.$this->userProfile->fullName.'; ';
                $header .= ' '.Yii::t('quotes', 'Expires').' '.$this->targetModel->renderAttribute('expirationDate');
                $header .= '<br />';
            }

            // Attachments info (include links to media items if
            if(!empty($this->attachments)){
                $header .= '<br /><hr />';
                $header .= CHtml::tag('strong', array(), Yii::t('media', 'Attachments:'))."<br />";
                foreach($this->attachments as $attachment){
                    $header .= CHtml::tag('span', array('class' => 'email-attachment-text'), $attachment['filename']).'<br />';
                }
            }

            $this->_actionHeader = $header.'<br /><hr />';
        }
        return $this->_actionHeader;
    }

	/**
	 * Getter for {@link credentials}
	 * returns Credentials
	 */
	public function getCredentials() {
		if(!isset($this->_credentials)) {
			if($this->credId == Credentials::LEGACY_ID)
				$this->_credentials = false;
			else {
				$cred = Credentials::model()->findByPk($this->credId);
				$this->_credentials = empty($cred) ? false : $cred;
			}
		}
		return $this->_credentials;
	}

    public function getFrom(){
        if(!isset($this->_from)) {
			if($this->credentials)
				$this->_from = array(
					'name' => $this->credentials->auth->senderName,
					'address' => $this->credentials->auth->email
				);
			else
				$this->_from = array(
					'name' => $this->userProfile->fullName,
					'address' => $this->userProfile->emailAddress
				);
		}
        return $this->_from;
    }

    public function setFrom($from){
        $this->_from = $from;
    }

    /**
     * Magic getter for {@link insertableAttributes}.
     *
     * Herein is defined how the insertable attributes are put together for each
     * different model class.
     * @return array
     */
    public function getInsertableAttributes(){
        if(!isset($this->_insertableAttributes)){
            $ia = array(); // Insertable attributes
            if($this->targetModel !== false){
                // Assemble the arrays to be used in putting together insertable attributes.
                //
				// What the labels will look like in the insertable attributes
                // dropdown. {attr} replaced with attribute name, {model}
                // replaced with model.
                $labelFormat = '{attr}';
                // The headers for each model/section, indexed by model class.
                $headers = array();
                // The active record objects corresponding to each model class.
                $models = array($this->modelName => $this->targetModel);
                switch($this->modelName){
                    case 'Quote':
                        // There will be many more models whose attributes we want
                        // to insert, so prefix each one with the model name to
                        // distinguish the current section:
                        $labelFormat = '{model}: {attr}';
                        $headers = array(
                            'Accounts' => 'Account Attributes',
                            'Quote' => 'Quote Attributes',
                            'Contacts' => 'Contact Attributes',
                        );
                        $models = array_merge($models, array(
                            'Accounts' => $this->targetModel->getLinkedModel('accountName'),
                            'Contacts' => $this->targetModel->contact,
                                ));
                        break;
                    case 'Contacts':
                        $headers = array(
                            'Contacts' => 'Contact Attributes',
                        );
                        break;
                    case 'Accounts':
                        $labelFormat = '{model}: {attr}';
                        $headers = array_merge($headers, array(
                            'Accounts' => 'Account Attributes'
                                ));
                        break;
                    case 'Opportunity':
                        $labelFormat = '{model}: {attr}';
                        $headers = array(
                            'Opportunity' => 'Opportunity Attributes',
                        );
                        // Grab the first associated contact and use it (since that
                        // covers the most common use case of one contact, one opportunity)
                        $contactIds = explode(' ', $this->targetModel->associatedContacts);
                        if(!empty($contactIds[0])){
                            $contact = Contacts::model()->findByPk($contactIds[0]);
                            if(!empty($contact)){
                                $headers['Contacts'] = 'Contact Attributes';
                                $models['Contacts'] = $contact;
                            }
                        }
                        // Obtain the account info as well, if available:
                        if(!empty($this->targetModel->accountName)){
                            $account = Accounts::model()->findAllByPk($this->targetModel->accountName);
                            if(!empty($account)){
                                $headers['Accounts'] = 'Account Attributes';
                                $models['Accounts'] = $account;
                            }
                        }
                        break;
                    case 'Services':
                        $labelFormat = '{model}: {attr}';
                        $headers = array(
                            'Cases' => 'Case Attributes',
                            'Contacts' => 'Contact Attributes',
                        );
                        $models = array(
                            'Cases' => $this->targetModel,
                            'Contacts' => Contacts::model()->findByPk($this->targetModel->contactId),
                        );
                        break;
                }

                $headers = array_map(function($e){
                            return Yii::t('app', $e);
                        }, $headers);

                foreach($headers as $modelName => $title){
                    $model = $models[$modelName];
                    if($model instanceof CActiveRecord){
                        $ia[$title] = array();
                        $friendlyName = Yii::t('app', rtrim($modelName, 's'));
                        foreach($model->attributeLabels() as $fieldName => $label){
                            $attr = trim($model->renderAttribute($fieldName, false));
                            $fullLabel = strtr($labelFormat, array(
                                '{model}' => $friendlyName,
                                '{attr}' => $label
                                    ));
                            if($attr !== '' && $attr != '&nbsp;')
                                $ia[$title][$fullLabel] = $attr;
                        }
                    }
                }
            }
            $this->_insertableAttributes = $ia;
        }
        return $this->_insertableAttributes;
    }

    /**
     * Magic getter for {@link phpMailer}
     * @return \PHPMailer
     */
    public function getMailer(){
        if(!isset($this->_mailer)){
            require_once(realpath(Yii::app()->basePath.'/components/phpMailer/class.phpmailer.php'));

            $phpMail = new PHPMailer(true); // the true param means it will throw exceptions on errors, which we need to catch
            $phpMail->CharSet = 'utf-8';

			$cred = $this->credentials;
			if($cred){ // Use an individual user email account if specified and valid
				$phpMail->IsSMTP();
				$phpMail->Host = $cred->auth->server;
				$phpMail->Port = $cred->auth->port;
				$phpMail->SMTPSecure = $cred->auth->security;
				if(!empty($cred->auth->password)){
					$phpMail->SMTPAuth = true;
					$cred->auth->emailUser('user');
					$phpMail->Username = $cred->auth->user;
					$phpMail->Password = $cred->auth->password;
				}
				// Use the specified credentials (which should have the sender name):
				$phpMail->AddReplyTo($cred->auth->email, $cred->auth->senderName);
				$phpMail->SetFrom($cred->auth->email, $cred->auth->senderName);
				$this->from = array('address' => $cred->auth->email, 'name' => $cred->auth->senderName);
			}else{ // Use the system default (legacy method)
				switch(Yii::app()->params->admin->emailType){
					case 'sendmail':
						$phpMail->IsSendmail();
						break;
					case 'qmail':
						$phpMail->IsQmail();
						break;
					case 'smtp':
						$phpMail->IsSMTP();

						$phpMail->Host = Yii::app()->params->admin->emailHost;
						$phpMail->Port = Yii::app()->params->admin->emailPort;
						$phpMail->SMTPSecure = Yii::app()->params->admin->emailSecurity;
						if(Yii::app()->params->admin->emailUseAuth == 'admin'){
							$phpMail->SMTPAuth = true;
							$phpMail->Username = Yii::app()->params->admin->emailUser;
							$phpMail->Password = Yii::app()->params->admin->emailPass;
						}


						break;
					case 'mail':
					default:
						$phpMail->IsMail();
				}
				// Use sender specified in attributes/system (legacy method):
				$from = $this->from;
				if($from == null){ // if no from address (or not formatted properly)
					if(empty($this->userProfile->emailAddress))
						throw new Exception('Your profile doesn\'t have a valid email address.');

					$phpMail->AddReplyTo($this->userProfile->emailAddress, $this->userProfile->fullName);
					$phpMail->SetFrom($this->userProfile->emailAddress, $this->userProfile->fullName);
				} else{
					$phpMail->AddReplyTo($from['address'], $from['name']);
					$phpMail->SetFrom($from['address'], $from['name']);
				}
			}

            $this->_mailer = $phpMail;
        }
        return $this->_mailer;
    }

    /**
     * Magic getter for {@link recipientContacts}
     */
    public function getRecipientContacts(){
        if(!isset($this->_recipientContacts)){
            $contacts = array();
            foreach($this->recipients as $target){
                $contacts[$target[1]] = Contacts::model()->findByAttributes(array('email' => $target[1]));
            }
            $this->_recipientContacts = $contacts;
        }
        return $this->_recipientContacts;
    }

    /**
     * Magic getter for {@link recipients}
     * @return array
     */
    public function getRecipients(){
        if(empty($this->_recipients)){
            $this->_recipients = array();
            foreach(array('to', 'cc', 'bcc') as $recList){
                if(!empty($this->mailingList[$recList])){
                    foreach($this->mailingList[$recList] as $target){
                        $this->_recipients[] = $target;
                    }
                }
            }
        }
        return $this->_recipients;
    }

    /**
     * Magic getter for {@link signature}
     *
     * Retrieves the email signature from the preexisting body, or from the
     * user's profile if none can be found.
     *
     * @return string
     */
    public function getSignature(){
        if(!isset($this->_signature)){
            $profile = $this->getUserProfile();
            if(!empty($profile))
                $this->_signature = $this->getUserProfile()->getSignature(true);
            else
                $this->_signature = null;
        }
        return $this->_signature;
    }

    /**
     * Magic getter for {@link targetModel}
     */
    public function getTargetModel(){
        if(!isset($this->_targetModel)){
            if(isset($this->modelId, $this->modelName)){
                $this->_targetModel = X2Model::model($this->modelName)->findByPk($this->modelId);
                if($this->_targetModel === null)
                    $this->_targetModel = false;
            } else{
                $this->_targetModel = false;
            }
//			if(!(bool) $this->_targetModel)
//				throw new Exception('InlineEmail used on a target model name and primary key that matched no existing record.');
        }
        return $this->_targetModel;
    }

    public function setTargetModel(X2Model $model){
        $this->_targetModel = $model;
    }

    /**
     * Magic getter for {@link templateModel}
     * @return type
     */
    public function getTemplateModel($id = null){
        $newTemp = !empty($id);
        if($newTemp){
            $this->template = $id;
            $this->_templateModel = null;
        }else{
            $id = $this->template;
        }
        if(empty($this->_templateModel)){
            $this->_templateModel = Docs::model()->findByPk($id);
        }
        return $this->_templateModel;
    }

    /**
     * Magic getter for {@link trackingImage}
     * @return type
     */
    public function getTrackingImage(){
        if(!isset($this->_uniqueId, $this->_trackingImage)){
            $this->_trackingImage = null;
            $trackUrl = null;
            if(!Yii::app()->params->noSession){
                $trackUrl = Yii::app()->controller->createAbsoluteUrl('actions/emailOpened', array('uid' => $this->uniqueId, 'type' => 'open'));
            }else{
		// This might be a console application! In that case, there's
		// no controller application component available.
                $url = Yii::app()->externalBaseUrl;
                if(!empty($url))
                    $trackUrl = "$url/index.php/actions/emailOpened?uid={$this->uniqueId}&type=open";
                else
                    $trackUrl = null;
            }
            if($trackUrl != null)
                $this->_trackingImage = '<img src="'.$trackUrl.'"/>';
        }
        return $this->_trackingImage;
    }

    /**
     * Magic setter for {@link uniqueId}
     */
    public function getUniqueId(){
        if(empty($this->_uniqueId))
            $this->_uniqueId = md5(uniqid(rand(), true));
        return $this->_uniqueId;
    }

    /**
     * Magic setter for {@link uniqueId}
     * @param string $value
     */
    public function setUniqueId($value){
        $this->_uniqueId = $value;
    }

    /**
     * Magic getter for {@link userProfile}
     * @return Profile
     */
    public function getUserProfile(){
        if(!isset($this->_userProfile)){
            if(empty($this->_userProfile)){
                if(Yii::app()->params->noSession){
                    // As a last resort: use admin
                    $this->_userProfile = Profile::model()->findByPk(1);
                }else{
                    // By default: if no profile was defined, and it's in a web
                    // session, use the current user's profile.
                    $this->_userProfile = Yii::app()->params->profile;
                }
            }
        }
        return $this->_userProfile;
    }

    /**
     * Magic setter for {@link userProfile}
     * @param Profile $profile
     */
    public function setUserProfile(Profile $profile){
        $this->_userProfile = $profile;
    }

    /**
     * Validation function for lists of email addresses.
     *
     * @param string $attribute
     * @param array $params
     */
    public function parseMailingList($attribute, $params = array()){
        if(!is_array($this->$attribute)){
            $splitString = explode(',', $this->$attribute);
        }else{
            $splitString = $this->$attribute;
        }
        $invalid = false;

        foreach($splitString as &$token){

            $token = trim($token);
            if(empty($token))
                continue;

            $matches = array();

            $emailValidator = new CEmailValidator;

            if($emailValidator->validateValue($token)) // if it's just a simple email, we're done!
                $this->mailingList[$attribute][] = array('', $token);
            elseif(strlen($token) < 255 && preg_match('/^"?([^"]*)"?\s*<(.+)>$/i', $token, $matches)){ // otherwise, it must be of the variety <email@example.com> "Bob Slydel"
                if(count($matches) == 3 && $emailValidator->validateValue($matches[2])){  // (with or without quotes)
                    $this->mailingList[$attribute][] = array($matches[1], $matches[2]);
                }else{
                    $invalid = true;
                    break;
                }
            }else{
                $invalid = true;
                break;
            }
        }

        if($invalid)
            $this->addError($attribute, Yii::t('app', 'Invalid email address list.'));
    }

    /**
     * Inserts a signature into the body, if none can be found.
     * @param array $wrap Wrap the signature in tags (index 0 opens, index 1 closes)
     */
    public function insertSignature($wrap = array('<br /><br />', '')){
        if(preg_match(self::insertedPattern('signature', '(.*)', 1, 'um'), $this->message, $matches)){
            $this->_signature = $matches[1];
        }else{
            $sig = self::insertedPattern('signature', $this->signature);
            if(count($wrap) >= 2){
                $sig = $wrap[0].$sig.$wrap[1];
            }
            if(strpos($this->message, '{signature}')){
                $this->message = str_replace('{signature}', $sig, $this->message);
            }else if($this->scenario != 'custom'){
                $this->insertInBody($sig);
            }
        }
        $this->insertInBody("<div>&nbsp;</div>");
    }

    /**
     * Search for an existing tracking image and insert a new one if none are present.
     *
     * Parses the tracking image and unique ID out of the body if there are any.
     *
     * The email will be tracked, but only if one and only one of the recipients
     * corresponds to a contact in X2CRM (remember, the user can switch the
     * recipient list at the last minute by modifying the "To:" field).
     *
     * Otherwise, there's absolutely no way of telling with any certainty who
     * exactly opened the email (all recipients will be sent the same email,
     * so any one of them could be the one who opens the email and accesses the
     * email tracking image). Thus, in such cases, it is pointless to create an
     * event/action that says "so-and so has opened an email" because who opened
     * the email is ambiguous and practically unknowable, and thus impractical
     * to create an email tracking record.
     *
     * @param bool $replace Reset the image markup and unique ID, and replace
     * 	the existing tracking image.
     */
    public function insertTrackingImage($replace = false){
        $recipientContacts = $this->recipientContacts;
        if(count($recipientContacts) == 1){ // There was only one addressee
            $theContact = reset($recipientContacts);
            if(!empty($theContact)){ // The one person who was sent an email is an existing contact
                $insertNew = true;
                $pattern = self::insertedPattern('track', '(<img.*\/>)', 1, 'u');
                if(preg_match($pattern, $this->message, $matchImg)){
                    if($replace){
                        // Reset unique ID and insert a new tracking image with a new unique ID
                        $this->_trackingImage = null;
                        $this->_uniqueId = null;
                        $this->message = replace_string($matchImg[0], self::insertedPattern('track', $this->trackingImage), $this->message);
                    }else{
                        $this->_trackingImage = $matchImg[1];
                        if(preg_match(self::UIDREGEX, $this->_trackingImage, $matchId)){
                            $this->_uniqueId = $matchId[1];
                            $insertNew = false;
                        }
                    }
                }
                if($insertNew){
                    $this->insertInBody(self::insertedPattern('track', $this->trackingImage));
                }
            }
        }
    }

    /**
     * Inserts something near the end of the body in the HTML email.
     *
     * @param string $content The markup/text to be inserted.
     * @param bool $beginning True to insert at the beginning, false to insert at the end.
     * @param bool $return True to modify {@link message}; false to return the modified body instead.
     */
    public function insertInBody($content, $beginning = 0, $return = 0){
        $insertToken = '{content}';
        $bodTag = $beginning ? '<body>' : '</body>';
        $modTag = $beginning ? $bodTag.$insertToken : $insertToken.$bodTag;
        $modBod = str_replace($bodTag, str_replace($insertToken, $content, $modTag), $this->message);
        if($return)
            return $modBod;
        else
            $this->message = $modBod;
    }

    /**
     * Generate a blank HTML document.
     *
     * @param string $content Optional content to start with.
     */
    public static function emptyBody($content = null){
        return "<html><head></head><body>$content</body></html>";
    }

    /**
     * Prepare the email body for sending or customization by the user.
     */
    public function prepareBody(){
        if(!$this->validate()){
            return false;
        }
        // Replace the existing body, if any, with a template, i.e. for initial
        // set-up or an automated email.
        if($this->scenario === 'template'){
            // Get the template and associated model

            if(!empty($this->templateModel)){
                // Replace variables in the subject and body of the email
                $this->subject = Docs::replaceVariables($this->templateModel->subject, $this->targetModel);
                // if(!empty($this->targetModel)) {
                $this->message = Docs::replaceVariables($this->templateModel->text, $this->targetModel, array('{signature}' => self::insertedPattern('signature', $this->signature)));
                // } else {
                // $this->insertInBody('<span style="color:red">'.Yii::t('app','Error: attempted using a template, but the referenced model was not found.').'</span>');
                // }
            }else{
                // No template?
                $this->message = self::emptyBody();
                $this->insertSignature();
            }
        }

        return true;
    }

    /**
     * Performs a send (or stage, or some other action).
     *
     * The tracking image is inserted at the very last moment before sending, so
     * that there is no chance of the user altering the body and deleting it
     * accidentally.
     *
     * @return array
     */
    public function send($createEvent = true){
        $this->insertTrackingImage();
        $this->status = $this->deliver();
        if($this->status['code'] == '200')
            $this->recordEmailSent($createEvent); // Save all the actions and events
        return $this->status;
    }

    /**
     * Save the tracking record for this email, but only if an image was inserted.
     *
     * @param integer $actionId ID of the email-type action corresponding to the record.
     */
    public function trackEmail($actionId){
        if(isset($this->_uniqueId)){
            $track = new TrackEmail;
            $track->actionId = $actionId;
            $track->uniqueId = $this->uniqueId;
            $track->save();
        }
    }

    /**
     * Make records of the email in every shape and form.
     *
     * This method is to be called only once the email has been sent.
     *
     * The basic principle behind what all is happening here: emails are getting
     * sent to people. Since the "To:" field in the inline email form is not
     * read-only, the emails could be sent to completely different people. Thus,
     * creating action and event records must be based exclusively on who the
     * email is addressed to and not the model from whose view the inline email
     * form (if that's how this model is being used) is submitted.
     */
    public function recordEmailSent($makeEvent = true){

        // The email record, with action header for display purposes:
        $emailRecordBody = $this->insertInBody(self::insertedPattern('ah', $this->actionHeader), 1, 1);
        $now = time();
		$recipientContacts = array_filter($this->recipientContacts);

        if(!empty($this->targetModel)){
            $model = $this->targetModel;
            if((bool) $model){
                if($model->hasAttribute('lastActivity')){
                    $model->lastActivity = $now;
                    $model->save();
                }
            }

            $action = new Actions;
            // These attributes will be the same regardless of the type of
            // email being sent:
            $action->completedBy = $this->userProfile->username;
            $action->createDate = $now;
            $action->dueDate = $now;
            $action->completeDate = $now;
            $action->complete = 'Yes';
            $action->actionDescription = $emailRecordBody;

            // These attributes are context-sensitive and subject to change:
            $action->associationId = $model->id;
            $action->associationType = lcfirst(get_class($model));
            $action->type = 'email';
            $action->visibility = isset($model->visibility) ? $model->visibility : 1;
            $action->assignedTo = $this->userProfile->username;
            if($this->modelName == 'Quote'){
				// Is an email being sent to the primary
				// contact on the quote? If so, the user is "issuing" the quote or
				// invoice, and it should have a special type.
				if(!empty($this->targetModel->contact)){
					if(array_key_exists($this->targetModel->contact->email, $recipientContacts)){
						$action->associationType = lcfirst(get_class($model));
						$action->associationId = $model->id;
						$action->type .= '_'.($model->type == 'invoice' ? 'invoice' : 'quote');
						$action->visibility = 1;
						$action->assignedTo = $model->assignedTo;
					}
				}
			}

            if($makeEvent && $action->save()){
                $this->trackEmail($action->id);
                // Create a corresponding event record. Note that special cases
                // may have to be written in the method Events->getText to
                // accommodate special association types apart from contacts,
                // in addition to special-case-handling here.
                if($makeEvent){
                    $event = new Events;
                    $event->type = 'email_sent';
                    $event->subtype = 'email';
                    $event->associationType = $model->myModelName;
                    $event->associationId = $model->id;
                    $event->user = $this->userProfile->username;

                    if($this->modelName == 'Quote'){
                        // Special "quote issued" or "invoice issued" event:
                        $event->subtype = 'quote';
                        if($this->targetModel->type == 'invoice')
                            $event->subtype = 'invoice';
                        $event->associationType = $this->modelName;
                        $event->associationId = $this->modelId;
                    }
                    $event->save();
                }
            }
        }

        // Create action history events and event feed events for all contacts that were in the recipient list:
        if($this->contactFlag){
            foreach($recipientContacts as $email => $contact){
                $contact->lastActivity = $now;
                $contact->update(array('lastActivity'));

                $skip = false;
                $skipEvent = false;
                if($this->modelName == 'Contacts'){
                    $skip = $this->targetModel->id == $contact->id;
                }else if($this->modelName == 'Quote'){
                    // An event has already been made for issuing the quote and
                    // so another event would be redundant.
                    $skipEvent = $this->targetModel->contact->id == $contact->id;
                }
                if($skip)
                // Only save the action history item/event if this hasn't
                // already been done.
                    continue;

                // These attributes will be the same regardless of the type of
                // email being sent:
                $action = new Actions;
                $action->completedBy = $this->userProfile->username;
                $action->createDate = $now;
                $action->dueDate = $now;
                $action->completeDate = $now;
                $action->complete = 'Yes';

                // These attributes are context-sensitive and subject to change:
                $action->associationId = $contact->id;
                $action->associationType = 'contacts';
                $action->type = 'email';
                $action->visibility = isset($contact->visibility) ? $contact->visibility : 1;
                $action->assignedTo = $this->userProfile->username;

                // Set the action's text to the modified email body
                $action->actionDescription = $emailRecordBody;
                // We don't really care about changelog events for emails; they're
                // set in stone anyways.
                $action->disableBehavior('changelog');

                if($action->save()){
                    $this->trackEmail($action->id);
                    // Now create an event for it:
                    if($makeEvent && !$skipEvent){
                        $event = new Events;
                        $event->type = 'email_sent';
                        $event->subtype = 'email';
                        $event->associationType = $contact->myModelName;
                        $event->associationId = $contact->id;
                        $event->user = $this->userProfile->username;
                        $event->save();
                    }
                }
            } // Loop over contacts
        } // Conditional statement: do all this only if the flag to perform action history creation for all contacts has been set
        // At this stage, if email tracking is to take place, "$action" should
        // refer to the action history item of the one and only recipient contact,
        // because there has been only one element in the recipient array to loop
        // over. If the target model is a contact, and the one recipient is the
        // contact itself, the action will be as declared before the above loop,
        // and it will thus still be properly associated with that contact.
    }

    /**
     * Perform the email delivery with PHPMailer.
     *
     * Any special authentication and security should take place in here.
     *
     * @throws Exception
     * @return array
     */
    public function deliver(){

        $addresses = $this->mailingList;
        $subject = $this->subject;
        $message = $this->message;
        $attachments = $this->attachments;

        $phpMail = $this->mailer;

        try{

            $this->addEmailAddresses($phpMail, $addresses);

            $phpMail->Subject = $subject;
            // $phpMail->AltBody = $message;
            $phpMail->MsgHTML($message);
            // $phpMail->Body = $message;
            // add attachments, if any
            if($attachments){
                foreach($attachments as $attachment){
                    if($attachment['temp']){ // stored as a temp file?
                        $file = 'uploads/media/temp/'.$attachment['folder'].'/'.$attachment['filename'];
                        if(file_exists($file)) // check file exists
                            if(filesize($file) <= (10 * 1024 * 1024)) // 10mb file size limit
                                $phpMail->AddAttachment($file);
                            else
                                throw new Exception("Attachment '{$attachment['filename']}' exceeds size limit of 10mb.");
                    } else{ // stored in media library
                        $file = 'uploads/media/'.$attachment['folder'].'/'.$attachment['filename'];
                        if(file_exists($file)) // check file exists
                            if(filesize($file) <= (10 * 1024 * 1024)) // 10mb file size limit
                                $phpMail->AddAttachment($file);
                            else
                                throw new Exception("Attachment '{$attachment['filename']}' exceeds size limit of 10mb.");
                    }
                }
            }

            $phpMail->Send();

            // delete temp attachment files, if they exist
            if($attachments){
                foreach($attachments as $attachment){
                    if($attachment['temp']){
                        $file = 'uploads/media/temp/'.$attachment['folder'].'/'.$attachment['filename'];
                        $folder = 'uploads/media/temp/'.$attachment['folder'];
                        if(file_exists($file))
                            unlink($file); // delete temp file
                        if(file_exists($folder))
                            rmdir($folder); // delete temp folder
                        TempFile::model()->deleteByPk($attachment['id']);
                    }
                }
            }

            $this->status['code'] = '200';
            $this->status['message'] = Yii::t('app', 'Email Sent!');
        }catch(phpmailerException $e){
            $this->status['code'] = '500';
            $this->status['message'] = $e->getMessage()." ".$e->getFile()." L".$e->getLine(); //Pretty error messages from PHPMailer
        }catch(Exception $e){
            $this->status['code'] = '500';
            $this->status['message'] = $e->getMessage()." ".$e->getFile()." L".$e->getLine(); //Boring error messages from anything else!
        }
        return $this->status;
    }

    /**
     * Adds email addresses to a PHPMail object
     * @param type $phpMail
     * @param type $addresses
     */
    public function addEmailAddresses(&$phpMail, $addresses){

        if(isset($addresses['to'])){
            foreach($addresses['to'] as $target){
                if(count($target) == 2)
                    $phpMail->AddAddress($target[1], $target[0]);
            }
        } else{
            if(count($addresses) == 2 && !is_array($addresses[0])){ // this is just an array of [name, address],
                $phpMail->AddAddress($addresses[1], $addresses[0]); // not an array of arrays
            }else{
                foreach($addresses as $target){  //this is an array of [name, address] subarrays
                    if(count($target) == 2)
                        $phpMail->AddAddress($target[1], $target[0]);
                }
            }
        }
        if(isset($addresses['cc'])){
            foreach($addresses['cc'] as $target){
                if(count($target) == 2)
                    $phpMail->AddCC($target[1], $target[0]);
            }
        }
        if(isset($addresses['bcc'])){
            foreach($addresses['bcc'] as $target){
                if(count($target) == 2)
                    $phpMail->AddBCC($target[1], $target[0]);
            }
        }
    }

}