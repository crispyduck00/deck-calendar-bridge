<?php
return ['routes'=>[
    ['name'=>'rules#index','url'=>'/api/rules','verb'=>'GET'],
    ['name'=>'rules#update','url'=>'/api/rules','verb'=>'PUT'],
    ['name'=>'control#status','url'=>'/api/status','verb'=>'GET'],
    ['name'=>'control#mode','url'=>'/api/mode','verb'=>'PUT'],
    ['name'=>'control#sync','url'=>'/api/sync','verb'=>'POST'],
    ['name'=>'control#boards','url'=>'/api/boards','verb'=>'GET'],
    ['name'=>'control#labels','url'=>'/api/boards/{boardId}/labels','verb'=>'GET','requirements'=>['boardId'=>'[1-9][0-9]*']],
    ['name'=>'control#calendarOptions','url'=>'/api/calendar-options','verb'=>'GET'],
    ['name'=>'control#preview','url'=>'/api/multi-preview/{cardId}','verb'=>'GET','requirements'=>['cardId'=>'[1-9][0-9]*']],
]];
