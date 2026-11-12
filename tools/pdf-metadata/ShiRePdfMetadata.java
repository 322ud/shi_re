import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.InputStream;
import java.util.LinkedHashMap;
import java.util.Map;
import javax.xml.XMLConstants;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.transform.OutputKeys;
import javax.xml.transform.TransformerFactory;
import javax.xml.transform.dom.DOMSource;
import javax.xml.transform.stream.StreamResult;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDDocumentInformation;
import org.apache.pdfbox.pdmodel.common.PDMetadata;
import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.Node;
import org.w3c.dom.NodeList;

/** Uses PDFBox from Mustang's JAR. Build for Java 11; no compiler needed on the server. */
public final class ShiRePdfMetadata {
    private static final String RDF = "http://www.w3.org/1999/02/22-rdf-syntax-ns#";
    private static final String DC = "http://purl.org/dc/elements/1.1/";
    private static final String XMP = "http://ns.adobe.com/xap/1.0/";
    private static final String PDF = "http://ns.adobe.com/pdf/1.3/";
    private static final String EXT = "http://www.aiim.org/pdfa/ns/extension/";
    private static final String SCHEMA = "http://www.aiim.org/pdfa/ns/schema#";
    private static final String PROPERTY = "http://www.aiim.org/pdfa/ns/property#";
    private static final String SHIRE = "https://github.com/322ud/shi_re/ns/metadata/1.0/";
    private static final String VALIDATOR = "https://github.com/322ud/inv_validator/ns/metadata/1.0/";

    public static void main(String[] args) throws Exception {
        if (args.length == 1 && "--check".equals(args[0])) {
            Class.forName("org.apache.pdfbox.Loader");
            System.out.println("ShiRE PDF metadata helper ready");
            return;
        }
        if (args.length != 3) throw new IllegalArgumentException("Expected input.pdf output.pdf metadata.xml");
        Map<String, String> values = readProperties(new File(args[2]));
        try (PDDocument pdf = Loader.loadPDF(new File(args[0]))) {
            if (pdf.isEncrypted() || !pdf.getSignatureDictionaries().isEmpty()) {
                throw new IllegalArgumentException("Cannot change encrypted or signed PDF");
            }
            PDMetadata original = pdf.getDocumentCatalog().getMetadata();
            if (original == null) throw new IllegalArgumentException("PDF/A XMP metadata missing");
            Document xmp;
            try (InputStream stream = original.exportXMPMetadata()) {
                xmp = factory().newDocumentBuilder().parse(stream);
            }
            NodeList roots = xmp.getElementsByTagNameNS(RDF, "RDF");
            if (roots.getLength() != 1) throw new IllegalArgumentException("Expected one XMP RDF root");
            Element rdf = (Element) roots.item(0);
            Element description = element(rdf, RDF, "rdf:Description", null);
            description.setAttributeNS(RDF, "rdf:about", "");

            removeProperty(rdf, DC, "title");
            Element title = element(description, DC, "dc:title", null);
            Element alt = element(title, RDF, "rdf:Alt", null);
            Element text = element(alt, RDF, "rdf:li", required(values, "title"));
            text.setAttributeNS(XMLConstants.XML_NS_URI, "xml:lang", "x-default");
            replace(rdf, description, XMP, "xmp:CreatorTool", required(values, "creator"));
            replace(rdf, description, PDF, "pdf:Producer", required(values, "producer"));

            addSchema(rdf, description, SHIRE, "shi_re", "ShiRE processing versions", values,
                new String[]{"libreOfficeVersion", "veraPDFVersion", "INValidatorVersion"});
            addSchema(rdf, description, VALIDATOR, "inv_validator", "INValidator validation components", values,
                new String[]{"kositValidatorVersion", "xrechnungSzenarioVersion"});

            PDDocumentInformation info = pdf.getDocumentInformation();
            info.setTitle(values.get("title"));
            info.setCreator(values.get("creator"));
            info.setProducer(values.get("producer"));

            TransformerFactory transformers = TransformerFactory.newDefaultInstance();
            transformers.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
            transformers.setAttribute(XMLConstants.ACCESS_EXTERNAL_DTD, "");
            transformers.setAttribute(XMLConstants.ACCESS_EXTERNAL_STYLESHEET, "");
            var transformer = transformers.newTransformer();
            transformer.setOutputProperty(OutputKeys.ENCODING, "UTF-8");
            transformer.setOutputProperty(OutputKeys.OMIT_XML_DECLARATION, "yes");
            ByteArrayOutputStream output = new ByteArrayOutputStream();
            transformer.transform(new DOMSource(xmp), new StreamResult(output));
            PDMetadata metadata = new PDMetadata(pdf);
            metadata.importXMPMetadata(output.toByteArray());
            pdf.getDocumentCatalog().setMetadata(metadata);
            pdf.save(args[1]);
        }
    }

    private static DocumentBuilderFactory factory() throws Exception {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newDefaultInstance();
        factory.setNamespaceAware(true);
        factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        factory.setAttribute(XMLConstants.ACCESS_EXTERNAL_DTD, "");
        factory.setAttribute(XMLConstants.ACCESS_EXTERNAL_SCHEMA, "");
        factory.setXIncludeAware(false);
        factory.setExpandEntityReferences(false);
        return factory;
    }

    private static Map<String, String> readProperties(File file) throws Exception {
        Document input = factory().newDocumentBuilder().parse(file);
        if (!"metadata".equals(input.getDocumentElement().getTagName())) {
            throw new IllegalArgumentException("Invalid metadata input");
        }
        Map<String, String> result = new LinkedHashMap<>();
        NodeList entries = input.getDocumentElement().getElementsByTagName("property");
        for (int i = 0; i < entries.getLength(); i++) {
            Element entry = (Element) entries.item(i);
            if (result.put(entry.getAttribute("name"), entry.getTextContent()) != null) {
                throw new IllegalArgumentException("Duplicate metadata property");
            }
        }
        return result;
    }

    private static String required(Map<String, String> values, String key) {
        String value = values.get(key);
        if (value == null || value.isBlank()) throw new IllegalArgumentException("Missing " + key);
        return value;
    }

    private static Element element(Element parent, String namespace, String name, String value) {
        Element child = parent.getOwnerDocument().createElementNS(namespace, name);
        if (value != null) child.setTextContent(value);
        parent.appendChild(child);
        return child;
    }

    private static void replace(Element rdf, Element description, String namespace, String name, String value) {
        removeProperty(rdf, namespace, name.substring(name.indexOf(':') + 1));
        element(description, namespace, name, value);
    }

    // XMP properties can be serialized as attributes or elements, using arbitrary prefixes.
    private static void removeProperty(Element rdf, String namespace, String name) {
        for (Node node = rdf.getFirstChild(); node != null; node = node.getNextSibling()) {
            if (!(node instanceof Element)) continue;
            Element description = (Element) node;
            if (!RDF.equals(description.getNamespaceURI()) || !"Description".equals(description.getLocalName())) continue;
            description.removeAttributeNS(namespace, name);
            for (Node child = description.getFirstChild(); child != null;) {
                Node next = child.getNextSibling();
                if (namespace.equals(child.getNamespaceURI()) && name.equals(child.getLocalName())) {
                    description.removeChild(child);
                }
                child = next;
            }
        }
    }

    private static void addSchema(Element rdf, Element description, String namespace, String prefix,
            String label, Map<String, String> values, String[] names) {
        Element bag;
        NodeList schemas = rdf.getElementsByTagNameNS(EXT, "schemas");
        if (schemas.getLength() == 0) {
            bag = element(element(description, EXT, "pdfaExtension:schemas", null), RDF, "rdf:Bag", null);
        } else {
            NodeList bags = ((Element) schemas.item(0)).getElementsByTagNameNS(RDF, "Bag");
            if (schemas.getLength() != 1 || bags.getLength() != 1) {
                throw new IllegalArgumentException("Invalid PDF/A extension schemas");
            }
            bag = (Element) bags.item(0);
        }
        // Replace only our schema, retaining Factur-X and other extension declarations.
        for (Node node = bag.getFirstChild(); node != null;) {
            Node next = node.getNextSibling();
            if (node instanceof Element) {
                NodeList uris = ((Element) node).getElementsByTagNameNS(SCHEMA, "namespaceURI");
                if (uris.getLength() == 1 && namespace.equals(uris.item(0).getTextContent())) bag.removeChild(node);
            }
            node = next;
        }
        Element schema = element(bag, RDF, "rdf:li", null);
        schema.setAttributeNS(RDF, "rdf:parseType", "Resource");
        element(schema, SCHEMA, "pdfaSchema:schema", label);
        element(schema, SCHEMA, "pdfaSchema:namespaceURI", namespace);
        element(schema, SCHEMA, "pdfaSchema:prefix", prefix);
        Element properties = element(element(schema, SCHEMA, "pdfaSchema:property", null), RDF, "rdf:Seq", null);
        for (String name : names) {
            removeProperty(rdf, namespace, name);
            String value = values.get(prefix + ":" + name);
            if (value == null) continue;
            element(description, namespace, prefix + ":" + name, value);
            Element property = element(properties, RDF, "rdf:li", null);
            property.setAttributeNS(RDF, "rdf:parseType", "Resource");
            element(property, PROPERTY, "pdfaProperty:name", name);
            element(property, PROPERTY, "pdfaProperty:valueType", "Text");
            element(property, PROPERTY, "pdfaProperty:category", "internal");
            element(property, PROPERTY, "pdfaProperty:description", "Version of " + name.replace("Version", "") + " used to process this invoice");
        }
    }
}
