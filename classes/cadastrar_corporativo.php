<?php 
  
  include_once "../db/db_config_mysql.php";
  include_once "../db/db_config_radius.php";
  include_once "../u2000/tl1_sender.php";
  // Inicia sessões 
  session_start();

  $nome = filter_input(INPUT_POST,'nome');
  $vasProfile = filter_input(INPUT_POST,'vasProfile');
  $serial_number = filter_input(INPUT_POST,'serial');
  $pacote_internet = filter_input(INPUT_POST,'pacote_internet');
  $modelo_ont = filter_input(INPUT_POST,'modelo_ont');
  $sip_number = filter_input(INPUT_POST,'numeroTel');
  $sip_password = filter_input(INPUT_POST,'passwordTel');
  $usuario = $_SESSION["id_usuario"];

  $porta_selecionado = filter_input(INPUT_POST,'porta_atendimento');
  $frame = filter_input(INPUT_POST,'frame');
  $slot = filter_input(INPUT_POST,'slot');
  $pon = filter_input(INPUT_POST,'pon');
  $cto = filter_input(INPUT_POST,'cto');
  $device = filter_input(INPUT_POST,'device');
  $contrato = filter_input(INPUT_POST,'contrato');
  $designacao = filter_input(INPUT_POST,'designacao');
  $vlan_associada = filter_input(INPUT_POST,'vlan_number');

  $internet = filter_input(INPUT_POST,"internet_check");
  $lanToLan = filter_input(INPUT_POST,"vlan_check");
  $iptv = filter_input(INPUT_POST,"iptv");
  $voip = filter_input(INPUT_POST,"voip");
  $modo_bridge = filter_input(INPUT_POST,'modo_bridge');

  ## ALIAS DO ASSINANTE PARA U2000
  $nomeAlias = str_replace(" ","_",$nome);

  ## CODIGO TAVA AKI
  $array_process_result = [];

############ CHECA O LIMITE DE ONT NO CLIENTE #############

  $sql_verifica_limite = "SELECT limite_equipamentos FROM ont WHERE contrato='$contrato'";
  $sql_limite_result = mysqli_query($conectar,$sql_verifica_limite);

  $limite_registro = "";
  
  while ($limite = mysqli_fetch_array($sql_limite_result, MYSQLI_BOTH)) 
  {
    $limite_registro = $limite['limite_equipamentos'];
  }

  if ($limite_registro < 1 AND $limite_registro != null) 
  {
    array_push($array_process_result,"Favor, entrar em contato com o TI, para solicitar aumento de registro de equipamentos");
  }

############### VERIFICA O MAC SE JA FOI CADASTRADO ################
  $sql_verifica_limite_ont = "SELECT serial,contrato FROM ont WHERE  serial = '$serial_number' LIMIT 1"; //verifica se ja existe o mac
  $executa_verifica_limite_ont = mysqli_query($conectar,$sql_verifica_limite_ont);
  
  if(mysqli_num_rows($executa_verifica_limite_ont) > 0) //se o resultado do limite for 1 ele cai aqui
  {
    $limiteONT = mysqli_fetch_array($executa_verifica_limite_ont, MYSQLI_BOTH);
    array_push($array_process_result,"MAC Já Cadastrado no contrato $limiteONT[contrato]");
  }

  ########## VERIFICA SE O CHECKBOX DOS SERVIÇOS FOI MARCADO  ################
  if(($internet != "Internet" && $lanToLan != "l2l" && $iptv != "IPTV" && $voip != "Telefone") )
  {
    array_push($array_process_result,"Nenhum Serviço Selecionado");
  }else{
################### CADASTRA A ONT NO BANCO LOCAL ######################
    $sql_registra_onu = ("INSERT INTO ont (contrato, serial, cto, tel_number, tel_user, tel_password, perfil, pacote, usuario_id,equipamento,porta)
      VALUES ('$contrato','$serial_number','$cto','$sip_number','$sip_number','$sip_password','$vasProfile','$pacote_internet','$usuario','$modelo_ont','$porta_selecionado')" );

    $cadastrar = mysqli_query($conectar,$sql_registra_onu);

    if($cadastrar)
    {
      array_push($array_process_result,"Cadastrado no Banco Local $designacao");

      $sql_atualiza_limite = "UPDATE ont SET limite_equipamentos=0 WHERE contrato = $contrato";
      $diminui_limite = mysqli_query($conectar,$sql_atualiza_limite);

      ####### CADASTRA A ONT NO U2000 ############
      $ontID = cadastrar_ont($device,$frame,$slot,$pon,$contrato,$nomeAlias,$cto,$porta_selecionado,$serial_number,$modelo_ont,$vasProfile,null,$designacao);
      
      $onuID = NULL; //zera ONUID para evitar problema de cash.
      
      sleep(1); //dorme para processar

      $tira_ponto_virgula = explode(";",$ontID);
      $check_sucesso = explode("EN=",$tira_ponto_virgula[1]);
      $remove_desc = explode("ENDESC=",$check_sucesso[1]);
      $errorCode = trim($remove_desc[0]);
      if($errorCode != "0")
      {
        $trato = tratar_errors($errorCode);
        array_push($array_process_result,"!!!! Houve erro ao inserir a ONT no u2000: $trato !!!!");

        //se der erro ele irá apagar o registro salvo na tabela local ont
        $sql_apagar_onu = ("DELETE FROM ont WHERE contrato = '$contrato' AND serial = '$serial_number'" );
        mysqli_query($conectar,$sql_apagar_onu);

        array_push($array_process_result,"Removido do Banco Local!");
      }else{
        array_push($array_process_result,"ONT Adicionada ao U2000!");

      ########## PEGANDO ID DA ONT PARA SALVAR ############
        $remove_barras_para_pegar_id = explode("---------------------------",$tira_ponto_virgula[1]);
        $filtra_espaco = explode("\r\n",$remove_barras_para_pegar_id[1]);
        $pega_id = explode("	",$filtra_espaco[2]);//posicao 4 será sempre o ONTID
        $onuID=trim($pega_id[4]);

        $insere_ont_id = "UPDATE ont SET ontID='$onuID' WHERE serial = '$serial_number'";
        $executa_insere_ont_id = mysqli_query($conectar,$insere_ont_id);

        array_push($array_process_result,"Inserido ID da ONT!");

      #### SELECT OLT IP ####
        $sql_pega_olt_ip = "SELECT olt_ip FROM pon WHERE deviceName='$device'";
        $executa_pega_olt_ip = mysqli_query($conectar,$sql_pega_olt_ip);
        while ($ip = mysqli_fetch_array($executa_pega_olt_ip, MYSQLI_BOTH))
        {
          $ip_olt = $ip['olt_ip'];
        }

    ############ INICIO DA ATIVACAO DOS SERVIÇOS #######
        if($lanToLan == "l2l")
        {
          array_push($array_process_result,"Lan to Lan");

        ######## ADICIONA A ONT NO RADIUS PARA PEGAR BANDA E IP ########
          $insere_ont_radius_username = "INSERT INTO radcheck( username, attribute, op, value)
              VALUES ( '2500/$slot/$pon/$serial_number@vertv', 'User-Name', ':=', '2500/$slot/$pon/$serial_number@vertv' )";

          $insere_ont_radius_password = "INSERT INTO radcheck( username, attribute, op, value)
              VALUES ( '2500/$slot/$pon/$serial_number@vertv', 'User-Password', ':=', 'vlan' )";

          $executa_query_username= mysqli_query($conectar_radius,$insere_ont_radius_username);
          $executa_query_password= mysqli_query($conectar_radius,$insere_ont_radius_password);
          
          if($executa_query_username && $executa_query_password)
            array_push($array_process_result,"ONT inserida no Radius IP Gerencia!");
          else
          {
            $deletar_onu_radius = " DELETE FROM radcheck WHERE username='2500/$slot/$pon/$serial_number@vertv' ";
            $executa_query_radius = mysqli_query($conectar_radius,$deletar_onu_radius);
            
            array_push($array_process_result,"Removido do Radius");
          }
        }
        
        if($internet == "Internet"){          
        
        ############### INSERE A BANDA NO RADIUS ################
          $insere_ont_radius_qos_profile = "INSERT INTO radreply( username, attribute, op, value) 
              VALUES ( '2500/$slot/$pon/$serial_number@vertv', 'Huawei-Qos-Profile-Name', ':=', '$pacote_internet' )";
          
          $executa_query_qos_profile= mysqli_query($conectar_radius,$insere_ont_radius_qos_profile);
          $executa_query_profile_ip_fixo = true;

          if($executa_query_username && $executa_query_profile_ip_fixo) 
          {
            array_push($array_process_result,"ONT inserida Banda no Radius!");

            $servicePortInternet = get_service_port_internet($device,$frame,$slot,$pon,$onuID,$contrato,$vasProfile,$modo_bridge);
            $tira_ponto_virgula = explode(";",$servicePortInternet);
            $check_sucesso = explode("EN=",$tira_ponto_virgula[1]);
            $remove_desc = explode("ENDESC=",$check_sucesso[1]);
            $errorCode = trim($remove_desc[0]);
            if($errorCode != "0") //se der erro na service port internet
            {
              $trato = tratar_errors($errorCode);

              //se der erro ele irá apagar o registro salvo na tabela local ont
              $sql_apagar_onu = ("DELETE FROM ont WHERE contrato = '$contrato' AND serial = '$serial_number'" );
              mysqli_query($conectar,$sql_apagar_onu);

              $deletar_onu_radius_banda = "DELETE FROM radreply WHERE username='2500/$slot/$pon/$serial_number@vertv' 
                AND attribute='Huawei-Qos-Profile-Name' ";
              mysqli_query($conectar_radius,$deletar_onu_radius_banda);

              deletar_onu_2000($device,$frame,$slot,$pon,$onuID,$ip_olt,$servicePortIPTV);
            }else{
              $remove_barras_para_pegar_id = explode("--------------",$tira_ponto_virgula[1]);
              $pegar_servicePorta_ID = explode("\r\n",$remove_barras_para_pegar_id[1]);
              $pega_id = explode("	",$pegar_servicePorta_ID[2]);//posicao 4 será sempre o ONTID
              
              $servicePortInternetID= $pega_id[0] - 1;
              
              array_push($array_process_result,"Service Port de Internet Criada $servicePortInternetID!");

              $insere_service_internet = "UPDATE ont SET service_port_internet=$servicePortInternetID WHERE serial = '$serial_number'";
              $executa_insere_service_internet = mysqli_query($conectar,$insere_service_internet);

              array_push($array_process_result,"Internet Cadastrada!");
            }
          }else{
            array_push($array_process_result,"Ocorreu um erro ao Inserir no Radius!");

            $deletar_onu_radius_banda = "DELETE FROM radreply WHERE username='2500/$slot/$pon/$serial_number@vertv' 
              AND attribute='Huawei-Qos-Profile-Name' ";
            $executa_query= mysqli_query($conectar_radius,$deletar_onu_radius_banda);

            array_push($array_process_result,"Removido do Radius");
          }
          
        }
        if($iptv == "IPTV") {
          array_push($array_process_result,"Selecione o IPTV");
        }
        if($voip == "Telefone") {
          array_push($array_process_result,"Telefone VOIP");
        }
      }

      
    
    }else{
      echo "OCORREU UM PROBLEMA AO CADASTRAR A ONT NO BANCO LOCAL";
    }
  }  
  

  


  ## CODIGO TERMINAVA AKI
  
  ##### FECHA AS CONEXOES COM OS BANCOS #####
  mysqli_close($conectar_radius);
  mysqli_close($conectar);
  
  echo "SERVIÇOS ATIVADOS \r";
  foreach($array_process_result as $result)
  {
    echo "$result \r";
  }
  
?>