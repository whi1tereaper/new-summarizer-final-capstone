<?php
declare(strict_types=1);
namespace App\Src\Support;

/** Short-lived, session-local form feedback. Never retain either password. */
final class StudentRegistrationForm
{
    public const CREATED='Account created successfully! Your Student ID was found in the approved enrollment registry. You can now log in.';
    public const UNAVAILABLE='Registration is temporarily unavailable. Please try again shortly. If you already submitted, try signing in or use account recovery.';
    public static function remember(array $input,array $errors): void
    {
        $values=[];
        foreach(['student_number'=>40,'email'=>100,'username'=>30] as $field=>$limit){
            $values[$field]=is_string($input[$field]??null)?mb_substr(trim($input[$field]),0,$limit):'';
        }
        $values['terms_accept']=($input['terms_accept']??null)==='1';
        $_SESSION['registration_feedback']=['values'=>$values,'errors'=>$errors,'expires'=>time()+900];
    }
    public static function consume(): array
    {
        $flash=$_SESSION['registration_feedback']??[];unset($_SESSION['registration_feedback']);
        if(!is_array($flash)||(int)($flash['expires']??0)<=time())return ['values'=>[],'errors'=>[]];
        return ['values'=>is_array($flash['values']??null)?$flash['values']:[],'errors'=>is_array($flash['errors']??null)?$flash['errors']:[]];
    }
}
