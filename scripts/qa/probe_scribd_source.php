<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../../app/src/Services/SourceResolver.php';
$url=$argv[1]??'';
$resolver=new App\Src\Services\SourceResolver();
try{
    $normalized=$resolver->validateUrl($url);
    $method=new ReflectionMethod($resolver,'downloadTextWithRedirectValidation');
    $fetch=$method->invoke($resolver,$normalized,5*1024*1024);
    $body=(string)$fetch['body'];$document=new DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new DOMXPath($document);
    $selectors=['articles'=>'//article','main'=>'//main','paragraphs'=>'//p','page_text'=>'//*[contains(concat(" ",normalize-space(@class)," ")," page_text ")]','text_layer'=>'//*[contains(concat(" ",normalize-space(@class)," ")," text_layer ")]','pages'=>'//*[contains(concat(" ",normalize-space(@class)," ")," page ")]','scripts'=>'//script','iframes'=>'//iframe','password_inputs'=>'//input[@type="password"]','document_body'=>'//*[@id="document_content"]'];
    $counts=[];foreach($selectors as $name=>$selector)$counts[$name]=$xpath->query($selector)->length;
    $text=(new ReflectionMethod($resolver,'extractReadableHtmlText'))->invoke($resolver,$body);
    try{$resolved=(new ReflectionMethod($resolver,'extractPublicScribdText'))->invoke($resolver,$body);$resolutionCode=$resolved!==''?'READABLE_PUBLIC_TEXT':'URL_DOCUMENT_VIEWER';}
    catch(App\Src\Support\ProcessingError $failure){$resolutionCode=$failure->errorCode;}
    $containers=[];
    foreach($xpath->query('//*[@class or @id or self::main or self::body]') as $node){
        $class=$node->getAttribute('class');$id=$node->getAttribute('id');
        if(!preg_match('/text|document|reader|content|transcript|page|main|body/i',$class.' '.$id.' '.$node->nodeName))continue;
        $chars=strlen(trim((string)$node->textContent));
        $containers[]=['tag'=>$node->nodeName,'id'=>substr($id,0,100),'class'=>substr($class,0,160),'text_bytes'=>$chars];
    }
    usort($containers,static fn($a,$b)=>$b['text_bytes']<=>$a['text_bytes']);
    $report=['host'=>parse_url($fetch['final_url'],PHP_URL_HOST),'content_type'=>$fetch['content_type'],'response_bytes'=>strlen($body),'element_counts'=>$counts,'extracted_bytes'=>strlen($text),'extracted_words'=>str_word_count($text),'resolution_code'=>$resolutionCode,
        'challenge_markers'=>array_values(array_filter(['captcha-delivery.com','geo.captcha-delivery.com','datadome','cf-chl-','challenge-platform','Just a moment','enable JavaScript','Access Denied','robot check'],static fn($marker)=>stripos($body,$marker)!==false)),
        'body_element_classes'=>array_slice(array_unique(array_filter(array_map(static fn($node)=>$node->getAttribute('class'),iterator_to_array($xpath->query('//body | //body/div | //body/main'))))),0,8),'text_containers'=>array_slice($containers,0,24)];
    if(isset($argv[2])){
        $destination=realpath($argv[2]);
        if(!$destination||str_contains(strtolower(str_replace('\\','/',$destination)),'/htdocs/'))throw new RuntimeException('Private diagnostics folder outside Apache required.');
        file_put_contents($destination.'/scribd-response.html',$body);
    }
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){echo json_encode(['failure_code'=>$error instanceof App\Src\Support\ProcessingError?$error->errorCode:'PROBE_FAILED','exception'=>get_class($error)]);exit(1);}
