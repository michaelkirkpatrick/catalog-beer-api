<?php
class PostmarkSendEmail {
    
    public $from = 'Catalog.beer info@catalog.beer';
    public $to = '';
    public $subject = '';
    public $tag = '';
    public $HtmlBody = '';
    public $TextBody = '';
    public $TrackOpens = false;
    public $TrackLinks = 'None';
    public $bcc = '';
    public $Attachments = [];
    public $ReplyTo = '';

    function generateBody($to, $subject, $tag, $htmlBody, $textBody, $attachments = [], $replyTo = ''){
        $this->to = $to;
        $this->bcc = 'michael@catalog.beer';
        $this->subject = $subject;
        $this->tag = $tag;
        $this->HtmlBody = $htmlBody;
        $this->TextBody = $textBody;
        $this->Attachments = $attachments;
        if(!empty($replyTo)){
            $this->ReplyTo = $replyTo;
        }else{
            // Omitted from the JSON, so Postmark replies go to From
            unset($this->ReplyTo);
        }
    }
}   
?>