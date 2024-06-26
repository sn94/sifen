<?php

namespace App\Services\FacturacionElectronica\sifen;

use App\Services\FacturacionElectronica\sifen\lib\barcode_generator;
use DateTime;
use DOMDocument;
use Exception;

class sifen
{

    public $ERROR =  NULL;
    private $name_llave_privada = "privada.key";
    private $name_certificado = "certificado.cer";
    private  $pass_llave_privada = "password";
    private $codigo_secreto = "ABCD0000000000000000000000000000";


    public function __construct($config)
    {

        if (array_key_exists("name_llave_privada",  $config))   $this->name_llave_privada =  $config['name_llave_privada'];

        if (array_key_exists("name_certificado",  $config)) $this->name_certificado =  $config['name_certificado'];

        if (array_key_exists("pass_llave_privada",  $config)) $this->pass_llave_privada =  $config['pass_llave_privada'];

        if (array_key_exists("codigo_secreto",  $config))  $this->codigo_secreto =  $config['codigo_secreto'];
    }
    private $contrasenaClavePrivada = null;
    private $presicion = 6; //Precisión de ceros en numeros flotantes

    /**
     * Esta función genera el xml para ser enviado a la sifen.
     *
     * @param string $json Recibe un string en formato json con todos los datos del archivo xml.
     * @param string $pass_llave_privada Contraseña de la llave privada.
     * @param string $name_llave_privada Nombre completo de la llave privada ubicado dentro de la carpeta llaves.
     * @param string $name_certificado Nombre completo de certificado ubicado dentro de la carpeta llaves.
     * @param string $codigo_secreto Necesario para la generación del hash del QR y proporcionado por la SIFEN.
     * @param bool $produccion False en el caso de estar en fase de pruebas o test, true en caso de estar ya en producción.
     * @param bool $retornar True para retornar el xml, false si solo se quiere generar en la carpeta de el archivo pero no retornarlo, por defecto retorna.
     * @return array $retorno Indice 0 es el XML, indice 1 es el número de la factura o Id
     */
    function generar_xml(string $json, bool $produccion = false, bool $retornar = true)
    {
        $this->checkDirectories();
        $this->contrasenaClavePrivada = "8OX%Rx2N";
        //Converts it into a PHP object
        $json_de = json_decode($json, true);

        //Obtenemos la hora dessde los servidores de la SIFEN siendo la misma dFecFirma
        $dFecFirma = $this->aravo();

        //Caculamos el valor de Id, para ello ---
        //Obtenermos la fecha en formato AAAAMMDD
        $dFeEmiDE = str_replace("T", " ", $json_de['DE'][0]['dFeEmiDE']);
        $fecha_de_emision_de = new DateTime($dFeEmiDE);
        $dFeEmiDE = $fecha_de_emision_de->format('Ymd');

        //Generamos un código de seguridad aleatoria de 9 digitos
        $dCodSeg = str_pad(mt_rand(000000001, 999999999), 9, "0", STR_PAD_LEFT); //Ver si usa una semilla irrepetible y rellenar el codSeg con cero al inicio en caso de no llegar a 9 digitos


        //Concatenamos todos los datos para la generación del codigo de seguridad
        $codi_seguridad = "0" . $json_de['DE'][0]['iTiDE'] . $json_de['DE'][0]['dRucEm'] . $json_de['DE'][0]['dDVEmi'] . $json_de['DE'][0]['dEst'] . $json_de['DE'][0]['dPunExp'] . $json_de['DE'][0]['dNumDoc'] . $json_de['DE'][0]['iTipCont'] . $dFeEmiDE . $json_de['DE'][0]['iTipEmi'] . $dCodSeg;

        //Calculamos el código verificador
        $cv = $this->mod11($codi_seguridad);

        $Id = $codi_seguridad . $cv;

        //Creamos todas las variables para los totales
        $dSubExe = 0;
        $dSubExe = 0;
        $dSubExo = 0;
        $dSub5 = 0;
        $dSub10 = 0;
        $dTotOpe = 0;
        $dTotDesc = 0;
        $dTotDescGlotem = 0;
        $dTotAntItem = 0;
        $dTotAnt = 0;
        $dPorcDescTotal = 0;
        $dDescTotal = 0;
        $dAnticipo = 0;
        $dRedon = 0;
        $dTotGralOpe = 0;
        $dIVA5 = 0;
        $dIVA10 = 0;
        $dLiqTotIVA5 = 0;
        $dLiqTotIVA10 = 0;
        $dIVAComi = 0;
        $dTotIVA = 0;
        $dBaseGrav5 = 0;
        $dBaseGrav10 = 0;
        $dTBasGraIVA = 0;

        //Generamos los items a ser de la factura
        $items = "";
        $cItems = 0; //Contador de la cantidad de items
        foreach ($json_de['items'] as $item) {
            //Formateamos algunos números enviados, como la cantidad y precio unitario
            $dCantProSer = is_float($item['dCantProSer']) ? $item['dCantProSer'] : number_format(round($item['dCantProSer']), 4, ".", "");
            $dPUniProSer = is_float($item['dPUniProSer']) ? $item['dPUniProSer'] : number_format(round($item['dPUniProSer']), 4, ".", "");

            //En caso de no enviar la unidad de medida usamos la Unidad - 77
            $cUniMed = isset($item['cUniMed']) ? $item['cUniMed'] : 77;
            $dDesUniMed = isset($item['dDesUniMed']) ? $item['dDesUniMed'] : 'UNI';

            //En caso de no enviar los siguientes valores lo ceramos
            $dDescItem = isset($item['dDescItem']) ? $item['dDescItem'] : 0;
            $dPorcDesIt = isset($item['dPorcDesIt']) ? $item['dPorcDesIt'] : 0;
            $dDescGloItem = isset($item['dDescGloItem']) ? $item['dDescGloItem'] : 0;
            $dAntPreUniIt = isset($item['dAntPreUniIt']) ? $item['dAntPreUniIt'] : 0;
            $dAntGloPreUniIt = isset($item['dAntGloPreUniIt']) ? $item['dAntGloPreUniIt'] : 0;

            //En caso de que no se envien ciertos valores lo ponemos por defecto al concepto del 10% del IVA
            $iAfecIVA = isset($item['iAfecIVA']) ? $item['iAfecIVA'] : $iAfecIVA = 1;
            $dDesAfecIVA = isset($item['dDesAfecIVA']) ? $item['dDesAfecIVA'] : $dDesAfecIVA = 'Gravado IVA';
            $dPropIVA = isset($item['dPropIVA']) ? $item['dPropIVA'] : $dPropIVA = 100;
            $dTasaIVA = isset($item['dTasaIVA']) ? $item['dTasaIVA'] : $dTasaIVA = 10;

            //Variable para el calculo de los totales
            $dTotBruOpeItem = $dCantProSer * $dPUniProSer;
            $dTotBruOpeItem = number_format(round($dTotBruOpeItem), $this->presicion, ".", "");
            $dTotOpeItem = ($dPUniProSer - $dDescItem - $dPorcDesIt - $dDescGloItem - $dAntPreUniIt - $dAntGloPreUniIt) * $dCantProSer;
            $dTotOpeItem = number_format(round($dTotOpeItem), $this->presicion, ".", "");

            //Dependiendo del porcentaje de IVA hacemos los calculos correspondientes
            switch ($dTasaIVA) {
                case 0: //Caso 0% de IVA exenta
                    $dBasGravIVA = 0;
                    $dLiqIVAItem = 0;
                    break;
                case 5: //Caso 5% de IVA
                    //Totales del Item
                    $dBasGravIVA = number_format(round(($dTotOpeItem * ($dPropIVA / 100)) / 1.05), $this->presicion, ".", "");
                    $dLiqIVAItem = number_format(round($dBasGravIVA * (5 / 100)), $this->presicion, ".", "");
                    //Totales del DE
                    $dSub5 += $dTotBruOpeItem;
                    $dSub5 = number_format(round($dSub5), $this->presicion, ".", "");
                    $dTotOpe += $dTotBruOpeItem;
                    $dTotOpe = number_format(round($dTotOpe), $this->presicion, ".", "");
                    $dTotGralOpe += $dTotBruOpeItem;
                    $dTotGralOpe = number_format(round($dTotGralOpe), $this->presicion, ".", "");
                    $dIVA5 += $dLiqIVAItem;
                    $dIVA5 = number_format(round($dIVA5), $this->presicion, ".", "");
                    $dTotIVA += $dLiqIVAItem;
                    $dBaseGrav5 += $dBasGravIVA;
                    $dBaseGrav5 = number_format(round($dBaseGrav5), $this->presicion, ".", "");
                    $dTBasGraIVA += $dBasGravIVA;
                    break;
                case 10: //Caso 10% de IVA
                    //Totales del Item
                    $dBasGravIVA = number_format(round(($dTotOpeItem * ($dPropIVA / 100)) / 1.1), $this->presicion, ".", "");
                    $dLiqIVAItem = number_format(round($dBasGravIVA * (10 / 100)), $this->presicion, ".", "");
                    //Totales del DE
                    $dSub10 += $dTotBruOpeItem;
                    $dSub10 = number_format(round($dSub10), $this->presicion, ".", "");
                    $dTotOpe += $dTotBruOpeItem;
                    $dTotOpe = number_format(round($dTotOpe), $this->presicion, ".", "");
                    $dTotGralOpe += $dTotBruOpeItem;
                    $dTotGralOpe = number_format(round($dTotGralOpe), $this->presicion, ".", "");
                    $dIVA10 += $dLiqIVAItem;
                    $dIVA10 = number_format(round($dIVA10), $this->presicion, ".", "");
                    $dTotIVA += $dLiqIVAItem;
                    $dBaseGrav10 += $dBasGravIVA;
                    $dBaseGrav10 = number_format(round($dBaseGrav10), $this->presicion, ".", "");
                    $dTBasGraIVA += $dBasGravIVA;
                    break;
            }


            $cItems++; //Sumar 1 por cada item
            $items .= <<<EOF
<gCamItem>
    <dCodInt>{$item['dCodInt']}</dCodInt>
    <dDesProSer>{$item['dDesProSer']}</dDesProSer>
    <cUniMed>$cUniMed</cUniMed>
    <dDesUniMed>$dDesUniMed</dDesUniMed>
    <dCantProSer>$dCantProSer</dCantProSer>
    <gValorItem>
        <dPUniProSer>$dPUniProSer</dPUniProSer>
        <dTotBruOpeItem>$dTotBruOpeItem</dTotBruOpeItem>
        <gValorRestaItem>
            <dDescItem>$dDescItem</dDescItem>
            <dPorcDesIt>$dPorcDesIt</dPorcDesIt>
            <dDescGloItem>$dDescGloItem</dDescGloItem>
            <dAntPreUniIt>$dAntPreUniIt</dAntPreUniIt>
            <dAntGloPreUniIt>$dAntGloPreUniIt</dAntGloPreUniIt>
            <dTotOpeItem>$dTotOpeItem</dTotOpeItem>
        </gValorRestaItem>
    </gValorItem>
    <gCamIVA>
        <iAfecIVA>$iAfecIVA</iAfecIVA>
        <dDesAfecIVA>$dDesAfecIVA</dDesAfecIVA>
        <dPropIVA>$dPropIVA</dPropIVA>
        <dTasaIVA>$dTasaIVA</dTasaIVA>
        <dBasGravIVA>$dBasGravIVA</dBasGravIVA>
        <dLiqIVAItem>$dLiqIVAItem</dLiqIVAItem>
    </gCamIVA>
</gCamItem>
EOF;
        }

        //Reemplazamos los datos dentro del modelo XML con los datos enviados
        $xml_crudo = <<<EOF
<rDE xmlns="http://ekuatia.set.gov.py/sifen/xsd" 
     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="https://ekuatia.set.gov.py/sifen/xsd siRecepDE_v150.xsd">
    <dVerFor>150</dVerFor>
    <DE Id="$Id">
        <dDVId>$cv</dDVId>
        <dFecFirma>$dFecFirma</dFecFirma>
        <dSisFact>{$json_de['DE'][0]['dSisFact']}</dSisFact>
        <gOpeDE>
            <iTipEmi>{$json_de['DE'][0]['iTipEmi']}</iTipEmi>
            <dDesTipEmi>{$json_de['DE'][0]['dDesTipEmi']}</dDesTipEmi>
            <dCodSeg>$dCodSeg</dCodSeg>
            <dInfoEmi>{$json_de['DE'][0]['dInfoEmi']}</dInfoEmi>
        </gOpeDE>
        <gTimb>
            <iTiDE>{$json_de['DE'][0]['iTiDE']}</iTiDE>
            <dDesTiDE>{$json_de['DE'][0]['dDesTiDE']}</dDesTiDE>
            <dNumTim>{$json_de['DE'][0]['dNumTim']}</dNumTim>
            <dEst>{$json_de['DE'][0]['dEst']}</dEst>
            <dPunExp>{$json_de['DE'][0]['dPunExp']}</dPunExp>
            <dNumDoc>{$json_de['DE'][0]['dNumDoc']}</dNumDoc>
            <dFeIniT>{$json_de['DE'][0]['dFeIniT']}</dFeIniT>
        </gTimb>
        <gDatGralOpe>
            <dFeEmiDE>{$json_de['DE'][0]['dFeEmiDE']}</dFeEmiDE>
            <gOpeCom>
                <iTipTra>{$json_de['DE'][0]['iTipTra']}</iTipTra>
                <dDesTipTra>{$json_de['DE'][0]['dDesTipTra']}</dDesTipTra>
                <iTImp>{$json_de['DE'][0]['iTImp']}</iTImp>
                <dDesTImp>{$json_de['DE'][0]['dDesTImp']}</dDesTImp>
                <cMoneOpe>{$json_de['DE'][0]['cMoneOpe']}</cMoneOpe>
                <dDesMoneOpe>{$json_de['DE'][0]['dDesMoneOpe']}</dDesMoneOpe>
            </gOpeCom>
            <gEmis>
                <dRucEm>{$json_de['DE'][0]['dRucEm']}</dRucEm>
                <dDVEmi>{$json_de['DE'][0]['dDVEmi']}</dDVEmi>
                <iTipCont>{$json_de['DE'][0]['iTipCont']}</iTipCont>
                <dNomEmi>{$json_de['DE'][0]['dNomEmi']}</dNomEmi>
                <dDirEmi>{$json_de['DE'][0]['dDirEmi']}</dDirEmi>
                <dNumCas>{$json_de['DE'][0]['dNumCas']}</dNumCas>
                <cDepEmi>{$json_de['DE'][0]['cDepEmi']}</cDepEmi>
                <dDesDepEmi>{$json_de['DE'][0]['dDesDepEmi']}</dDesDepEmi>
                <cDisEmi>{$json_de['DE'][0]['cDisEmi']}</cDisEmi>
                <dDesDisEmi>{$json_de['DE'][0]['dDesDisEmi']}</dDesDisEmi>
                <cCiuEmi>{$json_de['DE'][0]['cCiuEmi']}</cCiuEmi>
                <dDesCiuEmi>{$json_de['DE'][0]['dDesCiuEmi']}</dDesCiuEmi>
                <dTelEmi>{$json_de['DE'][0]['dTelEmi']}</dTelEmi>
                <dEmailE>{$json_de['DE'][0]['dEmailE']}</dEmailE>
                <gActEco>
                    <cActEco>{$json_de['DE'][0]['cActEco']}</cActEco>
                    <dDesActEco>{$json_de['DE'][0]['dDesActEco']}</dDesActEco>
                </gActEco>
            </gEmis>
            <gDatRec>
                <iNatRec>{$json_de['DE'][0]['iNatRec']}</iNatRec>
                <iTiOpe>{$json_de['DE'][0]['iTiOpe']}</iTiOpe>
                <cPaisRec>{$json_de['DE'][0]['cPaisRec']}</cPaisRec>
                <dDesPaisRe>{$json_de['DE'][0]['dDesPaisRe']}</dDesPaisRe>
                <iTiContRec>{$json_de['DE'][0]['iTiContRec']}</iTiContRec>
                <dRucRec>{$json_de['DE'][0]['dRucRec']}</dRucRec>
                <dDVRec>{$json_de['DE'][0]['dDVRec']}</dDVRec>
                <dNomRec>{$json_de['DE'][0]['dNomRec']}</dNomRec>
            </gDatRec>
        </gDatGralOpe>
        <gDtipDE>
            <gCamFE>
                <iIndPres>{$json_de['DE'][0]['iIndPres']}</iIndPres>
                <dDesIndPres>{$json_de['DE'][0]['dDesIndPres']}</dDesIndPres>
            </gCamFE>
            <gCamCond>
                <iCondOpe>{$json_de['DE'][0]['iCondOpe']}</iCondOpe>
                <dDCondOpe>{$json_de['DE'][0]['dDCondOpe']}</dDCondOpe>
                <gPaConEIni>
                    <iTiPago>{$json_de['DE'][0]['iTiPago']}</iTiPago>
                    <dDesTiPag>{$json_de['DE'][0]['dDesTiPag']}</dDesTiPag>
                    <dMonTiPag>{$json_de['DE'][0]['dMonTiPag']}</dMonTiPag>
                    <cMoneTiPag>{$json_de['DE'][0]['cMoneTiPag']}</cMoneTiPag>
                    <dDMoneTiPag>{$json_de['DE'][0]['dDMoneTiPag']}</dDMoneTiPag>
                    <gPagTarCD>
                        <iDenTarj>{$json_de['DE'][0]['iDenTarj']}</iDenTarj>
                        <dDesDenTarj>{$json_de['DE'][0]['dDesDenTarj']}</dDesDenTarj>
                        <iForProPa>{$json_de['DE'][0]['iForProPa']}</iForProPa>
                    </gPagTarCD>
                </gPaConEIni>
            </gCamCond>
            $items
        </gDtipDE>
        <gTotSub>
            <dSubExe>$dSubExe</dSubExe>
            <dSub5>$dSub5</dSub5>
            <dSub10>$dSub10</dSub10>
            <dTotOpe>$dTotOpe</dTotOpe>
            <dTotDesc>$dTotDesc</dTotDesc>
            <dTotDescGlotem>$dTotDescGlotem</dTotDescGlotem>
            <dTotAntItem>$dTotAntItem</dTotAntItem>
            <dTotAnt>$dTotAnt</dTotAnt>
            <dPorcDescTotal>$dPorcDescTotal</dPorcDescTotal>
            <dDescTotal>$dDescTotal</dDescTotal>
            <dAnticipo>$dAnticipo</dAnticipo>
            <dRedon>$dRedon</dRedon>
            <dTotGralOpe>$dTotGralOpe</dTotGralOpe>
            <dIVA5>$dIVA5</dIVA5>
            <dIVA10>$dIVA10</dIVA10>
            <dLiqTotIVA5>$dLiqTotIVA5</dLiqTotIVA5>
            <dLiqTotIVA10>$dLiqTotIVA10</dLiqTotIVA10>
            <dIVAComi>$dIVAComi</dIVAComi>
            <dTotIVA>$dTotIVA</dTotIVA>
            <dBaseGrav5>$dBaseGrav5</dBaseGrav5>
            <dBaseGrav10>$dBaseGrav10</dBaseGrav10>
            <dTBasGraIVA>$dTBasGraIVA</dTBasGraIVA>
        </gTotSub>
    </DE>
</rDE>
EOF;

        //Comenzamos la parte de la firma
        //Leer el contenido del certificado poara agregar en X509
        $certificado = file_get_contents(__DIR__ . '/llaves/' . $this->name_certificado);

        //Elimina las etiquetas BEGIN y END y otros caracteres no deseados
        $certificado = str_replace(array('-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'), '', $certificado);

        //Cargar la clave privada desde un archivo PEM
        $keyPass = $this->pass_llave_privada;
        $privateKey = openssl_pkey_get_private(file_get_contents(__DIR__ . '/llaves/' . $this->name_llave_privada), $keyPass);

        //Lo que se procede a firmar es todo el contenido entre las etiquetas DE, una vez firmado ya tiene todos los otros datos salvo el QR
        //Cargar el archivo XML que deseas firmar
        $xml = new DOMDocument();
        /*$xml->loadXML('<?xml version="1.0" encoding="UTF-899"?>' . $xml_crudo);*/
        $xml->loadXML($xml_crudo);


        //Obtener el contenido dentro de <DE></DE>, se canoniza con la función C14N del objeto DOMDocument()
        $de_contenido = $xml->getElementsByTagName('DE')->item(0)->C14N();

        //Calcular el valor de DigestValue (hash del contenido)
        $digestValue = base64_encode(hash('sha256', $de_contenido));

        //Firmar el xml formado arriba
        openssl_sign($de_contenido, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256);
        //openssl_sign($digestValue, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256);

        //Crear un objeto de firma XML
        $root = $xml->documentElement;
        $signature = $xml->createElement('Signature');
        $root->appendChild($signature);
        //$signature = $xml->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');


        //Creamos un objeto para poner dentro el resto SignedInfo
        $signedInfo = $xml->createElement('SignedInfo');
        $signature->appendChild($signedInfo);

        //Agregamos el C14N utilizado y el metodo de firma utilizado
        $canonicalizationMethod = $xml->createElement('CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $xml->createElement('SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($signatureMethod);

        //Crear un objeto de referencia
        $reference = $xml->createElement('Reference');
        $signedInfo->appendChild($reference);
        $reference->setAttribute('URI', '#' . $Id);

        //Crear el objeto de transformación
        $transforms = $xml->createElement('Transforms');
        $reference->appendChild($transforms);
        $transform = $xml->createElement('Transform');
        $transforms->appendChild($transform);
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');

        $transform2 = $xml->createElement('Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms->appendChild($transform2);

        //Crear el objeto DigestMethod
        $digestMethod = $xml->createElement('DigestMethod');
        $reference->appendChild($digestMethod);
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');

        //Agregamos a DigestValue
        $digestValueElement = $xml->createElement('DigestValue', $digestValue);
        $reference->appendChild($digestValueElement);

        //Codificar la firma en base64 y establecerla como el valor de SignatureValue
        $signatureValueElement = $xml->createElement('SignatureValue', base64_encode($signatureValue));
        $signature->appendChild($signatureValueElement);

        //Crear el objeto KeyInfo
        $keyInfo = $xml->createElement('KeyInfo');
        $signature->appendChild($keyInfo);

        //Crear el objeto X509Data
        $x509Data = $xml->createElement('X509Data');
        $keyInfo->appendChild($x509Data);

        //Crear el objeto X509Certificate y establecer el valor del certificado
        $x509Certificate = $xml->createElement('X509Certificate', $certificado);
        $x509Data->appendChild($x509Certificate);

        //Crear el objeto para el QR
        $gCamFuFD = $xml->createElement('gCamFuFD');
        $root->appendChild($gCamFuFD);

        //Creación del HASH del QR
        $concatenado = "nVersion=150&Id=" . $Id . "&dFeEmiDE=" . bin2hex($json_de['DE'][0]['dFeEmiDE']) . "&dRucRec=" . $json_de['DE'][0]['dRucRec'] . "&dTotGralOpe=" . $dTotGralOpe . "&dTotIVA=" . $dTotIVA . "&cItems=" . $cItems . "&DigestValue=" . bin2hex($digestValue) . "&IdCSC=0001";
        $concat_mas_codigo = $concatenado . $this->codigo_secreto;
        $hash_qr = hash('sha256', $concat_mas_codigo);

        //Crear un objeto de referencia al código QR
        if ($produccion) {
            $enlaceQR = "https://ekuatia.set.gov.py/consultas/qr?" . $concatenado . "&cHashQR=" . $hash_qr;
        } else {
            $enlaceQR = "https://www.ekuatia.set.gov.py/consultas-test/qr?" . $concatenado . "&cHashQR=" . $hash_qr;
        }

        $enlace_qr_cambio = str_replace("&", "&amp;", $enlaceQR); //Antes de la inserción de la URL en el XML, se deberá reemplazar los símbolos “&” por su equivalente en código html, el cual es “&amp;”.
        $dCarQR = $xml->createElement('dCarQR', $enlace_qr_cambio);
        $gCamFuFD->appendChild($dCarQR);

        //Generamos el QR para poder usar en la impresión
        //Librería usada para generar el QR https://github.com/kreativekorp/barcode
        // include 'lib/barcode-master/barcode.php';
        $generator = new barcode_generator();
        /* Create bitmap image and write to file. */
        $image = $generator->render_image("qr", $enlaceQR, "");

        $filename = __DIR__ . '/de/' . $Id . '.png';


        imagepng($image, $filename);
        imagedestroy($image);

        //Guardar el XML firmado en un archivo con el Id   
        // $nbytesw = $xml->save(__DIR__ . '/de/' . $Id . '.xml');
        //   if( $nbytesw === false)   print_r(("\n\nNot saved in ". __DIR__ . '/de/' . $Id . '.xml' ." \n\n"));
        $xml = $xml->saveXML();
        try {

            $filepath  = __DIR__ . '/de/' . $Id . '.xml';
            $myfile = fopen($filepath, "a") or die("Unable to open file!");

            fwrite($myfile, $xml);
            fclose($myfile);
            print_r("\nSaved XML\n");
        } catch (Exception $e) {
            print_r("\n" . $e->getMessage() . "\n");
        }

        //Creamos un array de retorno
        $retorno = array(
            $xml,
            $Id
        );

        if ($retornar) {
            //Mostramos el nuevo archivo XML
            return $retorno;
        }
    }


    /**
     * Esta función envia el xml a la SIFEN.
     *
     * @param string $num_xml Recibe un string en formato json con todos los datos del archivo xml.
     * @param string $name_llave_privada Nombre completo de la llave privada ubicado dentro de la carpeta llaves.
     * @param string $name_certificado Nombre completo del certificado ubicado dentro de la carpeta llaves.
     * @param bool $retornar True para retornar el xml de la sifen, false en caso de no importar lo que retorna.
     * @param bool $produccion False en el caso de estar en fase de pruebas o test, true en caso de estar ya en producción.
     * @return string Retorna el xml devulto por la SIFEN en un string.
     */
    function enviar_xml(string $num_xml, bool $produccion = false, bool $retornar = true)
    {
        //Enviamos el archivo al servidor de prueba de la SIFEN
        //Ruta al archivo XML que deseas enviar
        $rutaArchivoXML = __DIR__ . '/de/' . $num_xml . '.xml';

        //URL de destino donde deseas enviar el archivo
        if ($produccion)     $urlDestino = 'https://sifen.set.gov.py/de/ws/sync/recibe.wsdl';
        else   $urlDestino = 'https://sifen-test.set.gov.py/de/ws/sync/recibe.wsd';


        //Ruta al archivo de clave privada correspondiente al certificado
        $rutaClavePrivada = __DIR__ . '/llaves/' . $this->name_llave_privada;

        //Contraseña de la clave privada (si es necesaria)
        //$contrasenaClavePrivada = 'password';

        //Ruta al archivo de certificado en formato .crt
        $rutaCertificado = __DIR__ . '/llaves/' . $this->name_certificado;

        //Inicializa una sesión cURL
        $ch = curl_init();

        //Configura la URL de destino
        curl_setopt($ch, CURLOPT_URL, $urlDestino);

        //Habilita la opción POST para enviar datos
        curl_setopt($ch, CURLOPT_POST, true);

        //Configura el archivo XML para ser enviado
        $xmlData = file_get_contents($rutaArchivoXML);

        $dom = new DOMDocument();
        // print_r("\n\nXML DATA\n" . $xmlData);
        $dom->loadXML($xmlData); // Carga tu XML en el objeto DOMDocument

        //Obtener el contenido del elemento raíz (sin la declaración XML)
        $contenidoXML = $dom->saveXML($dom->documentElement);

        /*
        //Insertgamos el contenido del archivo XML dentro de la estructura SOAP, Pag. 36 del manual
        $soapEnvelope = '
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
            <soap:Header/>
            <soap:Body>
                <rEnviDe xmlns="http://ekuatia.set.gov.py/sifen/xsd">
                    <dId>20</dId>
                    <xDE>
                        ' . $contenidoXML . '
                    </xDE>
                </rEnviDe>
            </soap:Body>
        </soap:Envelope>';
        */

        //Insertgamos el contenido del archivo XML dentro de la estructura SOAP, Pag. 36 del manual
        /*<?xml version="1.0" encoding="UTF-8"?>*/
        $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>
        <env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
            <env:Header/>
            <env:Body>
                <rEnviDe xmlns="http://ekuatia.set.gov.py/sifen/xsd">
                    <dId>25</dId>
                    <xDE>
                        ' . $contenidoXML . '
                    </xDE>
                </rEnviDe>
            </env:Body>
        </env:Envelope>';

        $soapEnvelope = $this->normalizeXML(  $soapEnvelope );
        file_put_contents( __DIR__ .'/DE_'.date('YmdHi').'.txt' , $soapEnvelope);
        //Solo para saber qué le enviamos a la SIFEN
        //echo $soapEnvelope;

        //DomSOAP, canonizamos antes de enviar el XML
        //$domSOAP = new DOMDocument();
        //$domSOAP->loadXML($soapEnvelope);
        //$soapEnvelope = $domSOAP->C14N();

        curl_setopt($ch, CURLOPT_POSTFIELDS, $soapEnvelope);

        //Establece la cabecera Content-Type para el XML
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));

        //Configura el certificado y la clave privada para la autenticación
        curl_setopt($ch, CURLOPT_SSLCERT, $rutaCertificado);
        curl_setopt($ch, CURLOPT_SSLKEY, $rutaClavePrivada);

        //Establece la contraseña de la clave privada (si es necesaria)
        curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->contrasenaClavePrivada);

        //Habilita la verificación del certificado del servidor
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // <------  true

        //Configura la ruta al archivo de la CA raíz (si es necesario)
        //curl_setopt($ch, CURLOPT_CAINFO, 'ruta/a/la/ca-raiz.crt');

        //Configura para recibir una respuesta del servidor
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //extra opc
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 900);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        //end extras

        //Ejecuta la solicitud cURL
        $response = curl_exec($ch);
         //Cierra la sesión cURL
         curl_close($ch);

       
        //Ruta donde deseas guardar el archivo XML
        $rutaArchivo = __DIR__ . '/sifen_responses/' . $num_xml . '_respuesta_sifen.xml';

        //Guardar el contenido XML de la SIFEN en el archivo
        file_put_contents($rutaArchivo, $response);

        //Verifica si hubo errores en la solicitud cURL
       
         // Check for errors and display the error message
         if ($errno = curl_errno($ch)) {
            $error_message = curl_strerror($errno);
            $this->ERROR =  "cURL error ({$errno}):\n {$error_message}";
        } else if (preg_match("/^<html>/", $response) == 1) {
            $this->ERROR =  "Error de la SET BIG-IP logout page";
        }

        $response_arr =   $this->parseXmlToArray($response);

        return $response_arr;

       
 
      
    }



    //Enviar lote
    function enviar_lote_xml(bool $produccion = false, bool $retornar = true)
    {
        $this->checkDirectories();
        //Enviamos el archivo al servidor de prueba de la SIFEN
        //Ruta al archivo XML que deseas enviar

        $xml_list = [];

        $xml_files_names =  glob(__DIR__ . '/de/*.xml');
        foreach ($xml_files_names as $item) {
            if (is_file($item)) {

                //Configura el archivo XML para ser enviado
                $xmlData = file_get_contents($item);
                $dom = new DOMDocument();
                if (!$xmlData) {
                    $this->ERROR = "Uno de los XMLs proporcionados esta vacio";
                    return NULL;
                }
                $dom->loadXML($xmlData);
                $contenidoXML = $dom->saveXML($dom->documentElement);
                $contenidoXMLVal = $this->normalizeXML($contenidoXML);
                array_push($xml_list,   $contenidoXMLVal);
            }
        }


        //URL de destino donde deseas enviar el archivo

        $nroXmls = sizeof($xml_list);

        if ($nroXmls  ==  0) {
            $this->ERROR  = ("No se ha proporcionado ningun Documentos electrónicos XML");
            return NULL;
        } else if ($nroXmls  > 50) {
            $this->ERROR  = ("Sólo se permiten un máximo de 50 Documentos electrónicos XML por lote");
            return NULL;
        }



        if ($produccion) {
            $urlDestino =  "https://sifen.set.gov.py/de/ws/async/recibe-lote.wsdl";
        } else {
            $urlDestino =  "https://sifen-test.set.gov.py/de/ws/async/recibe-lote.wsdl";
        }

        //Ruta al archivo de clave privada correspondiente al certificado
        $rutaClavePrivada = __DIR__ . '/llaves/' . $this->name_llave_privada;

        //Contraseña de la clave privada (si es necesaria)
        //$contrasenaClavePrivada = 'password';

        //Ruta al archivo de certificado en formato .crt
        $rutaCertificado = __DIR__ . '/llaves/' . $this->name_certificado;

        //Inicializa una sesión cURL
        $ch = curl_init();

        //Configura la URL de destino
        curl_setopt($ch, CURLOPT_URL, $urlDestino);

        //Habilita la opción POST para enviar datos
        curl_setopt($ch, CURLOPT_POST, true);



        $contenidoLotes = implode("\n", $xml_list);

        //Insertgamos el contenido del archivo XML dentro de la estructura SOAP, Pag. 36 del manual
        /*<?xml version="1.0" encoding="UTF-8"?>*/
        $id = 1; //identificador de envio

        $output =  base64_encode(bzcompress($contenidoLotes, 9));
        $zipAsBase64 = $output;
        print_r('Contenido lotes' . $zipAsBase64);
        $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>
        <env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
            <env:Header/>
            <env:Body>
                <rEnvioLote xmlns="http://ekuatia.set.gov.py/sifen/xsd">
                    <dId>' . $id . '</dId>
                    <xDE>' . $zipAsBase64 . '</xDE>
                </rEnvioLote>
            </env:Body>
        </env:Envelope>';

        file_put_contents("sifen.xml", $soapEnvelope);
        $soapEnvelope = $this->normalizeXML($soapEnvelope);


        curl_setopt($ch, CURLOPT_POSTFIELDS, $soapEnvelope);

        //Establece la cabecera Content-Type para el XML
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml;charset=utf-8'));

        //Configura el certificado y la clave privada para la autenticación
        curl_setopt($ch, CURLOPT_SSLCERT, $rutaCertificado);
        curl_setopt($ch, CURLOPT_SSLKEY, $rutaClavePrivada);

        //Establece la contraseña de la clave privada (si es necesaria)
        curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->contrasenaClavePrivada);

        //Habilita la verificación del certificado del servidor
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        //Configura para recibir una respuesta del servidor
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //end extras 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 100);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9000);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        //Ejecuta la solicitud cURL
        $response = curl_exec($ch);

        // Check for errors and display the error message
        if ($errno = curl_errno($ch)) {
            $error_message = curl_strerror($errno);
            $this->ERROR =  "cURL error ({$errno}):\n {$error_message}";
        } else if (preg_match("/^<html>/", $response) == 1) {
            $this->ERROR =  "Error de la SET BIG-IP logout page";
        }
        print_r($this->ERROR);
        print_r("eNVIO LOTE " . $response);
        //Ruta donde deseas guardar el archivo XML

        //write log
        $xmlfilenames_processed = implode("\n", $xml_files_names);
        $internalLotId = date('YmdHi');

        file_put_contents(__DIR__ . '/logs/envio_lote_' . $internalLotId, $xmlfilenames_processed);

        //write sifen resp
        $rutaArchivo = __DIR__ . '/sifen_responses/' . 'lote_' . $internalLotId . '_respuesta_sifen.xml';

        //Guardar el contenido XML de la SIFEN en el archivo
        file_put_contents($rutaArchivo, $response);

        //Verifica si hubo errores en la solicitud cURL
        if (curl_errno($ch)) {
            return curl_error($ch);
        }

        //Cierra la sesión cURL
        curl_close($ch);

        //Maneja la respuesta del servidor si es necesario
        if ($response) {
            return $response;
        }
    }
    /**
     * Esta función obtiene la hora actual del servidor aravo1.set.gov.py de la SET.
     * 
     * @param bool $crudo True para retornar la hora cruda del servidor, luego se debe de trasformar en una hora legible, false en caso de querer retornar "Y-m-d H:i:s".
     * @return string Retorna la fecha y la hora exacta del servidor de la SET.
     */
    public function aravo(bool $crudo = false)
    {
        $ntp_server = 'aravo1.set.gov.py';
        $port = 123; //Puerto estándar para NTP

        //Formatea el paquete de solicitud NTP (protocolo NTP)
        $request_packet = "\x1b" . str_repeat("\0", 47);

        //Crea un socket UDP
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            echo "Error al crear el socket: " . socket_strerror(socket_last_error());
            exit();
        }

        //Establece un tiempo de espera para el socket (en segundos)
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

        //Conecta al servidor NTP
        if (socket_connect($socket, $ntp_server, $port) === false) {
            echo "Error al conectar al servidor NTP: " . socket_strerror(socket_last_error());
            exit();
        }

        //Envía la solicitud al servidor NTP
        if (socket_send($socket, $request_packet, strlen($request_packet), 0) === false) {
            echo "Error al enviar la solicitud al servidor NTP: " . socket_strerror(socket_last_error());
            exit();
        }

        //Recibe la respuesta del servidor NTP
        if (socket_recv($socket, $response_packet, 48, 0) === false) {
            echo "Error al recibir la respuesta del servidor NTP: " . socket_strerror(socket_last_error());
            exit();
        }

        //Cierra el socket
        socket_close($socket);

        //Analiza la respuesta NTP para obtener el timestamp
        $unpack_response = unpack("N12", $response_packet);
        $timestamp = sprintf("%.0f", ($unpack_response[9] - 2208988800));

        //Convierte el timestamp a una fecha y hora legible usable par ala SIFEN
        $fecha_hora = date("Y-m-d\TH:i:s", $timestamp);

        if ($crudo) {
            return $timestamp;
        } else {
            return $fecha_hora;
        }
    }


    /**
     * Función para calcular el modulo 11
     * @param string string del codigo generado de 47 digitos para calcular el DV
     * @return int Retorna el código verificador
     */
    function mod11(string $numero, int $basemax = 11)
    {
        $codigo = 0;
        $numero_al = '';

        for ($i = 0; $i < strlen($numero); $i++) {
            $c = substr($numero, $i, 1);
            $codigo = ord(strtoupper($c));

            if (!($codigo >= 48 && $codigo <= 57)) {
                $numero_al .= $codigo;
            } else {
                $numero_al .= $c;
            }
        }

        $k = 2;
        $total = 0;

        for ($i = strlen($numero_al); $i >= 1; $i--) {
            if ($k > $basemax) {
                $k = 2;
            }
            $numero_aux = intval(substr($numero_al, $i - 1, 1));
            $total += ($numero_aux * $k);
            $k++;
        }

        $resto = $total % 11;

        if ($resto > 1) {
            $digito = 11 - $resto;
        } else {
            $digito = 0;
        }

        return strval($digito);
    }


    function  consultaRUC($id, $ruc, $produccion = false)
    {
        try {


            $urlDestino = "https://sifen.set.gov.py/de/ws/consultas/consulta-ruc.wsdl";
            if ($produccion == false) {
                $urlDestino =    "https://sifen-test.set.gov.py/de/ws/consultas/consulta-ruc.wsdl";
            }
            /*   if (!this . cert) {
                reject("Antes debe Autenticarse");
            }
            if (!this . key) {
                reject("Antes debe autenticarse");
            } */




            $soapXMLData = '<?xml version="1.0" encoding="UTF-8"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Header/><env:Body><rEnviConsRUC xmlns="http://ekuatia.set.gov.py/sifen/xsd"><dId>' . $id . '</dId><dRUCCons>' . $ruc . '</dRUCCons></rEnviConsRUC></env:Body></env:Envelope>';
            $soapXMLData =  $this->normalizeXML($soapXMLData);

            $ch = curl_init();

            //Configura la URL de destino
            curl_setopt($ch, CURLOPT_URL, $urlDestino);

            //Habilita la opción POST para enviar datos
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soapXMLData);
            //Establece la cabecera Content-Type para el XML
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
            //Configura el certificado y la clave privada para la autenticación
            $rutaClavePrivada = __DIR__ . '/llaves/' . $this->name_llave_privada;
            $rutaCertificado = __DIR__ . '/llaves/' . $this->name_certificado;

            if (!file_exists($rutaClavePrivada)) {
                die('Private key file does not exist = ' . $rutaClavePrivada);
            }
            if (!is_readable($rutaClavePrivada)) {
                die('Private key file is not readable');
            }

            curl_setopt($ch, CURLOPT_SSLCERT, $rutaCertificado);
            curl_setopt($ch, CURLOPT_SSLKEY, $rutaClavePrivada);

            //Establece la contraseña de la clave privada (si es necesaria)
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->contrasenaClavePrivada);
            //Habilita la verificación del certificado del servidor
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            //Configura para recibir una respuesta del servidor
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //extra opc

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 100);
            curl_setopt($ch, CURLOPT_TIMEOUT, 900);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            //end extras
            $response = curl_exec($ch);



            // Check for errors and display the error message
            if ($errno = curl_errno($ch)) {
                $error_message = curl_strerror($errno);
                $this->ERROR =  "cURL error ({$errno}):\n {$error_message}";
            } else if (preg_match("/^<html>/", $response) == 1) {
                $this->ERROR =  "Error de la SET BIG-IP logout page";
            }

            $response_arr =   $this->parseXmlToArray($response);

            return $response_arr;
        } catch (Exception $e) {
            $this->ERROR =  "Error al consultar RUC " . $e->getMessage();
        }
    }




    function  cancelarEvento($id, $params, $produccion = false)
    {
        try {




            $urlDestino =  "https://sifen.set.gov.py/de/ws/eventos/evento.wsdl";
            if ($produccion == false) {
                $urlDestino =   "https://sifen-test.set.gov.py/de/ws/eventos/evento.wsdl";
            }


            $soapXMLDataBck = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><rEnviEventoDe xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dId>7</dId><dEvReg><gGroupGesEve xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd"><rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd"><rEve Id="4"><dVerFor>150</dVerFor><gGroupTiEvt><rGeVeCan><Id>01800164490001002000000722023110211449635639</Id><mOtEve>prueba de cancelacion</mOtEve></rGeVeCan></gGroupTiEvt><dFecFirma>2024-04-15T16:54:58</dFecFirma></rEve><Signature><SignedInfo><CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/><SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/><Reference URI="#4"><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/><Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></Transforms><DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><DigestValue>NjFlMDJiZWJiMzlmZWYxMTA0NjQ1MTJjMDIxOTllNzBlM2Q3MGZjZDZlOGNjYzg4ZDY0ZWNmZjg4OTMxNTM3YQ==</DigestValue></Reference></SignedInfo><SignatureValue>bbOJggmX6m28c9bLmlY9cCPpLXPK2T+8+saA+Sy21ypv/7ODHQpkQLUrS+dcmjDCVjGga91JLg0oyqJhUNpFSHe/94huX3R2rH77chdc3ZgMnei8B5N54/BGjXv4afwdVoHP+H3I+XwKktAVoUdc8xmUwgyMlgPxZ8rndTDYs9G42eUo4jEmh3IDHebhVXFC/PWw+DZ7xsnF9UP27IIJ56VVLE9fg0mGBcxNY8boKjT5XJk8KaK+JT2/ro6sIB7Eooh85n0WdxrAbu9GoLNcTuy3s752zlwtNDvb2rlez4zsilJwew2gHXZXrFnOLMGRI37URG66RBefw6yHkYsUXQ==</SignatureValue><KeyInfo><X509Data><X509Certificate>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</X509Certificate></X509Data></KeyInfo></Signature></rGesEve></gGroupGesEve></dEvReg></rEnviEventoDe></env:Body></env:Envelope>';
 
            $soapXMLDataCorrecto = '<?xml version="1.0" encoding="utf-8"?>
            <env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
                <env:Body>
            <rEnviEventoDe xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                <dId>7</dId>
                <dEvReg>
                    <gGroupGesEve xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd">
                        <rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd">
                            <rEve Id="4">
                                <dFecFirma>2024-04-15T16:40:32</dFecFirma>
                                <dVerFor>150</dVerFor>
                                <gGroupTiEvt>
                                    <rGeVeCan>
                                        <Id>01800164490001002000000722023110211449635639</Id>
                                        <mOtEve>prueba de cancelacion</mOtEve>
                                    </rGeVeCan>
                                </gGroupTiEvt>
                            </rEve><Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
              <SignedInfo>
                <CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
                <SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
                <Reference URI="#4">
                  <Transforms>
                    <Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"></Transform>
                    <Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></Transform>
                  </Transforms>
                  <DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                  <DigestValue>ZjE3YjQxNmVmMDg4ZDQ0ODQ2YThlNDIzYjQ5MjM4NTQ3ZjU4ZDIwNDY5MzIyYmUxOGY1NGYzNGY4MTEwZDhhOQ==</DigestValue>
                </Reference>
              </SignedInfo>
              <SignatureValue>XONtGwOIkHXe10lTBJluYCh1Na7Ft9yHLgOwaIQwFmVx9+0AIehHwKUdl0jFQ8M87X3PM5tbBh54WmZ1pBVsjGN7rGzZ8sGz2DqlH/sRDqM1abdqLKrtbcOlwh961MQoIspXhLHFknNJCQ0ANfT0pWIrRgPrc5V/WqM4W88m7N48vGa5GPrWAMW5MqUAVzlauBA0+kYGcDBFQ6B63kEH+hMCxVLwGcbFK1/F9NPemHyGPMsp/uOivYCkVpNOTkXPB2OUzcX4VJU812dbNtRLOWb5F3IeVtzaSVCepUvpKpL8qcWiZiaObO7GIMGsMTl9mxybRti6TJ7+nUVXRcmYhQ==</SignatureValue>
              <KeyInfo>
                <X509Data>
                  <X509Certificate>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</X509Certificate>
                </X509Data>
              </KeyInfo>
            </Signature>
                        </rGesEve>
                    </gGroupGesEve>
                </dEvReg>
            </rEnviEventoDe>  </env:Body></env:Envelope>
            ';
            $soapXMLData =  $this->normalizeXML($soapXMLDataBck);

            file_put_contents(__DIR__ . '/cancelacion'.date('YmdHi').'.xml', $soapXMLData);
            $ch = curl_init();

            //Configura la URL de destino
            curl_setopt($ch, CURLOPT_URL, $urlDestino);

            //Habilita la opción POST para enviar datos
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soapXMLData);
            //Establece la cabecera Content-Type para el XML
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
            //Configura el certificado y la clave privada para la autenticación
            $rutaClavePrivada = __DIR__ . '/llaves/' . $this->name_llave_privada;
            $rutaCertificado = __DIR__ . '/llaves/' . $this->name_certificado;

            if (!file_exists($rutaClavePrivada)) {
                die('Private key file does not exist = ' . $rutaClavePrivada);
            }
            if (!is_readable($rutaClavePrivada)) {
                die('Private key file is not readable');
            }

            curl_setopt($ch, CURLOPT_SSLCERT, $rutaCertificado);
            curl_setopt($ch, CURLOPT_SSLKEY, $rutaClavePrivada);

            //Establece la contraseña de la clave privada (si es necesaria)
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->contrasenaClavePrivada);
            //Habilita la verificación del certificado del servidor
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            //Configura para recibir una respuesta del servidor
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //extra opc

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 100);
            curl_setopt($ch, CURLOPT_TIMEOUT, 900);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            //end extras
            $response = curl_exec($ch);



            // Check for errors and display the error message
            if ($errno = curl_errno($ch)) {
                $error_message = curl_strerror($errno);
                $this->ERROR =  "cURL error ({$errno}):\n {$error_message}";
            } else if (preg_match("/^<html>/", $response) == 1) {
                $this->ERROR =  "Error de la SET BIG-IP logout page";
            }

            $response_arr =   $this->parseXmlToArray($response);

            return $response_arr;
        } catch (Exception $e) {
            $this->ERROR =  "Error al enviar Evento " . $e->getMessage();
         
        }
    }

    //utils


    function  firmarDocumento($IdDelDocumento,  $xml_crudo,  $tag)
    {
        $dFecFirma = $this->aravo();
        $xml = new DOMDocument();
        /*$xml->loadXML('<?xml version="1.0" encoding="UTF-899"?>' . $xml_crudo);*/
        $xml->loadXML($xml_crudo);
        //Obtener el contenido dentro de <DE></DE>, se canoniza con la función C14N del objeto DOMDocument()
        $de_contenido = $xml->getElementsByTagName($tag)->item(0)->C14N();

        //Calcular el valor de DigestValue (hash del contenido)
        $digestValue = base64_encode(hash('sha256', $de_contenido));

        //Firmar el xml formado arriba
        $keyPass = $this->pass_llave_privada;
        $privateKey = openssl_pkey_get_private(file_get_contents(__DIR__ . '/llaves/' . $this->name_llave_privada), $keyPass);
        openssl_sign($de_contenido, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256);
        //openssl_sign($digestValue, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256);

        //Crear un objeto de firma XML
        //opcion 1 sobre la raiz
        /*  $root = $xml->documentElement;
        $signature = $xml->createElement('Signature');
        $root->appendChild($signature); */

        $rGesEve = $xml->getElementsByTagName("rEnviEventoDe")[0]
            ->getElementsByTagName("dEvReg")[0]
            ->getElementsByTagName("gGroupGesEve")[0]
            ->getElementsByTagName("rGesEve")[0];

        $rEveNode = $xml->getElementsByTagName("rEnviEventoDe")[0]
            ->getElementsByTagName("dEvReg")[0]
            ->getElementsByTagName("gGroupGesEve")[0]
            ->getElementsByTagName("rGesEve")[0]
            ->getElementsByTagName("rEve")[0];
        if (sizeof($rEveNode->getElementsByTagName("dFecFirma")) > 0)
            $rEveNode->getElementsByTagName("dFecFirma")[0]->nodeValue  = $dFecFirma;
        else {
            $dFecFirmaNode = $xml->createElement('dFecFirma');
            $dFecFirmaNode->nodeValue  = $dFecFirma;
            $rEveNode->appendChild(  $dFecFirmaNode );

        }


        $signature = $xml->createElement('Signature'); //agregar xmlns="http://www.w3.org/2000/09/xmldsig#"
        $rGesEve->appendChild($signature);


        //$signature = $xml->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');


        //Creamos un objeto para poner dentro el resto SignedInfo
        $signedInfo = $xml->createElement('SignedInfo');
        $signature->appendChild($signedInfo);

        //Agregamos el C14N utilizado y el metodo de firma utilizado
        $canonicalizationMethod = $xml->createElement('CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $xml->createElement('SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($signatureMethod);

        //Crear un objeto de referencia
        $reference = $xml->createElement('Reference');
        $signedInfo->appendChild($reference);
        $reference->setAttribute('URI', '#' . $IdDelDocumento);

        //Crear el objeto de transformación
        $transforms = $xml->createElement('Transforms');
        $reference->appendChild($transforms);
        $transform = $xml->createElement('Transform');
        $transforms->appendChild($transform);
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');

        $transform2 = $xml->createElement('Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms->appendChild($transform2);

        //Crear el objeto DigestMethod
        $digestMethod = $xml->createElement('DigestMethod');
        $reference->appendChild($digestMethod);
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');

        //Agregamos a DigestValue
        $digestValueElement = $xml->createElement('DigestValue', $digestValue);
        $reference->appendChild($digestValueElement);

        //Codificar la firma en base64 y establecerla como el valor de SignatureValue
        $signatureValueElement = $xml->createElement('SignatureValue', base64_encode($signatureValue));
        $signature->appendChild($signatureValueElement);

        //Crear el objeto KeyInfo
        $keyInfo = $xml->createElement('KeyInfo');
        $signature->appendChild($keyInfo);

        //Crear el objeto X509Data
        $x509Data = $xml->createElement('X509Data');
        $keyInfo->appendChild($x509Data);

        //Crear el objeto X509Certificate y establecer el valor del certificado
        $certificado = file_get_contents(__DIR__ . '/llaves/' . $this->name_certificado);

        //Elimina las etiquetas BEGIN y END y otros caracteres no deseados
        $certificado = str_replace(array('-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'), '', $certificado);

        $x509Certificate = $xml->createElement('X509Certificate', $certificado);
        $x509Data->appendChild($x509Certificate);
        $xml = $xml->saveXML();
        $xml = str_replace( '<?xml version="1.0" encoding="utf-8"?>' ,"",  $xml );

      $fulxml =  '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
            <env:Body>
            '.$xml.'
            </env:Body>
            </env:Envelope>';
        return $this->normalizeXML($fulxml);
    }

    function checkDirectories()
    {
        $dirs =  ["./sifen_responses", "./de", "./logs"];
        foreach ($dirs as $d)
            if (!is_dir($d))
                mkdir($d);
    }
    function normalizeXML($xml)
    {
        $xml_ = str_replace("\r\n", "", $xml);
        $xml_ = preg_replace("/\n+/", "", $xml_);
        $xml_ =  preg_replace("/\t+/", "", $xml_);
        $xml_ = str_replace("    ", "", $xml_);
        //  $xml_ = str_replace(">    <", "><", "$xml_ ");
        $xml_ = preg_replace("/>\s+</", "><",  $xml_);


        $xml_ = preg_replace("/\r?\n|\r/", "", $xml_);
        return $xml_;
    }

    function  parseXmlToArray($xml)
    {


        $p = xml_parser_create();
        xml_parse_into_struct($p, $xml, $vals, $index);
        xml_parser_free($p);

        $data =  array_reduce($vals, function ($red, $item) {
            if (($item['value'] ?? null) &&  $item['value']   != "")
                $red[$item['tag']] = $item['value'];
            return $red;
        },  []);
        return $data;
    }
}
